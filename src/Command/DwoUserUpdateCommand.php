<?php

namespace Dwo\UserCommandsBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Dwo\UserCommandsBundle\UserMetadata;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PropertyAccess\Exception\NoSuchPropertyException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class DwoUserUpdateCommand extends Command
{
    protected static $defaultName = 'dwo:user:update';

    /** @var EntityManagerInterface */
    private $em;
    /** @var UserPasswordEncoderInterface */
    private $encoder;
    /** @var ValidatorInterface */
    private $validator;
    /** @var SymfonyStyle */
    private $io;
    /** @var UserMetadata */
    private $userMetadata;
    /** @var ObjectRepository */
    private $repo;

    public function __construct(EntityManagerInterface $em, UserPasswordEncoderInterface $encoder, ValidatorInterface $validator)
    {
        parent::__construct();

        $this->em = $em;
        $this->encoder = $encoder;
        $this->validator = $validator;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('update a user')
            ->setHelp($this->getCommandHelp())
            ->addArgument('id', InputArgument::OPTIONAL, 'database id or unique idendifier (eg: username, email)')
            ->addOption('username', 'u', InputOption::VALUE_NONE, 'update the unique idendifier (eg: username, email)')
            ->addOption('password', 'p', InputOption::VALUE_NONE, 'update the password')
            ->addOption('admin', 'a', InputOption::VALUE_NONE, 'update the admin rol')
            ->addOption('fields', 'f', InputOption::VALUE_REQUIRED, 'update this fields from user (coma seperated)')
            ->addOption('answers', null, InputOption::VALUE_REQUIRED, 'answers for all fields as json_string');
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->userMetadata = new UserMetadata($this->em);
        $this->repo = $this->em->getRepository($this->userMetadata->findUserClassName());
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $this->io->title('Update User Command Interactive Wizard');

        $username = $input->getOption('username');
        $password = $input->getOption('password');
        $admin = $input->getOption('admin');
        $fields = $input->getOption('fields') ? explode(',', $input->getOption('fields')) : [];

        $user = $this->findUser($input);

        $this->io->writeln(sprintf('Updating User <info>%s</info>', $user->getUsername()));

        if ($username) {
            array_unshift($fields, $this->userMetadata->findFieldByType('username'));
        }
        if ($password) {
            array_unshift($fields, $this->userMetadata->findFieldByType('password'));
        }
        if ($admin) {
            array_unshift($fields, 'is_admin');
        }

        $accessor = PropertyAccess::createPropertyAccessor();

        if (empty($fields)) {
            $choices = ['username', 'password', 'is_admin'];
            $question = new ChoiceQuestion('What should be updated? (multiselect possible)', $choices);
            $question->setMultiselect(true);

            foreach ($this->io->askQuestion($question) as $field) {
                if (in_array($field, ['username', 'password'])) {
                    $field = $this->userMetadata->findFieldByType($field);
                }
                array_unshift($fields, $field);
            }
        }

        $this->io->section('Now asking: '.implode(', ', $fields));

        $answers = $oldValues = [];
        foreach ($fields as $field) {
            $oldValues[$field] = '';

            try {
                $oldValue = $accessor->getValue($user, $field);
                if (!is_scalar($oldValue)) {
                    $oldValue = json_encode($oldValue);
                }
                if (30 < strlen($oldValue)) {
                    $oldValue = substr($oldValue, 0, 30).'..';
                }
            } catch (NoSuchPropertyException $e) {
                $oldValue = null;
            }

            $question = new Question($field, $oldValues[$field] = $oldValue);
            if ('password' === $field) {
                $oldValues[$field] = '******';
                $question = new Question('Password (your type will be hidden)');
                $question->setHidden(true);
            } elseif ('is_admin' === $field) {
                $oldValue = in_array('ROLE_ADMIN', $accessor->getValue($user, 'roles'));
                $question = new ConfirmationQuestion('Is admin', $oldValues[$field] = $oldValue);
            }

            $answers[$field] = $this->io->askQuestion($question);
        }

        $this->io->section('Please confirm the changes');
        foreach ($answers as $field => $answer) {
            $oldValue = $oldValues[$field];
            if (is_bool($oldValue)) {
                $oldValue = $oldValue ? 'true' : 'false';
            }
            if (is_bool($answer)) {
                $answer = $answer ? 'true' : 'false';
            }
            $this->io->writeln(sprintf(' * %s: <comment>%s</comment> to <comment>%s</comment>', $field, $oldValue, $answer));
        }

        if (!$this->io->askQuestion(new ConfirmationQuestion(''))) {
            throw new \Exception('Abort');
        }

        $input->setOption('answers', json_encode($answers));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $answers = json_decode($input->getOption('answers'), 1);
        if (!is_array($answers)) {
            throw new \InvalidArgumentException('invalid answers - must be a valid json string');
        }

        $user = $this->findUser($input);

        $accessor = PropertyAccess::createPropertyAccessor();

        foreach ($answers as $field => $value) {

            switch ($field) {
                case 'password':
                    $this->validate($value, [new Length(['min' => 6]), new NotBlank()], 'password');
                    $value = $this->encoder->encodePassword($user, (string) $value);
                    break;

                case 'is_admin':
                    $roles = $user->getRoles();
                    if ($value) {
                        if (!in_array('ROLE_ADMIN', $roles)) {
                            $roles[] = 'ROLE_ADMIN';
                        }
                    } else {
                        unset($roles[array_search('ROLE_ADMIN', $roles)]);
                    }
                    $field = 'roles';
                    $value = $roles;
                    break;

            }

            try {
                $accessor->setValue($user, $field, $value);
            } catch (NoSuchPropertyException $e) {

                throw $e;
                # $accessor->setValue($user, $this->userMetadata->findUserFieldByType(), $value);
            }
        }

        $this->validate($user);

        $this->em->persist($user);
        $this->em->flush();

        $this->io->success(sprintf('User was successfully updated'));

        return 0;
    }

    private function findUser(InputInterface $input): UserInterface
    {
        $id = $input->getArgument('id');

        if (null === $user = $this->repo->find($id)) {
            $username = $this->userMetadata->findFieldByType('username');
            if (null === $user = $this->repo->findOneBy([$username => $id])) {
                throw new \Exception('could not find a user with id: '.$id);
            }
        }

        return $user;
    }

    private function getCommandHelp(): string
    {
        return <<<'HELP'
The <info>%command.name%</info> command update a user

  <info>php %command.full_name% ID</info>

HELP;
    }

    private function validate($value, $constraints = null, string $name = null)
    {
        $violation = $this->validator->validate($value, $constraints);
        if ($violation->count()) {
            throw new \Exception(($name ? $name.' ' : '').(string) $violation);
        }
    }
}