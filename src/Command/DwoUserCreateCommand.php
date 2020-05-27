<?php

namespace Dwo\UserCommandsBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Dwo\UserCommandsBundle\UserMetadata;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class DwoUserCreateCommand extends Command
{
    protected static $defaultName = 'dwo:user:create';

    /** @var EntityManagerInterface */
    private $em;
    /** @var UserPasswordEncoderInterface */
    private $encoder;
    /** @var ValidatorInterface */
    private $validator;
    /** @var SymfonyStyle */
    private $io;
    /** @var UserMetadata */
    private $metadata;

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
            ->setDescription('Creates users and stores them in the database')
            ->setHelp($this->getCommandHelp())
            ->addArgument('username', InputArgument::OPTIONAL, 'The unique idendifier (eg: username, email)')
            ->addArgument('password', InputArgument::OPTIONAL, 'The plain password')
            ->addOption('admin', 'a', InputOption::VALUE_NONE, 'If set, the user is created as an administrator')
            ->addOption('fields', 'f', InputOption::VALUE_REQUIRED, 'add extra fields to user (coma seperated)')
            ->addOption('answers', null, InputOption::VALUE_REQUIRED, 'answers for extra fields as json_string');
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->metadata = new UserMetadata($this->em);
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $username = $input->getArgument('username');
        $password = $input->getArgument('password');
        $fields = $input->getOption('fields') ? explode(',', $input->getOption('fields')) : [];

        if (null !== $username && null !== $password && empty($fields)) {
            return;
        }

        $this->io->title('Add User Command Interactive Wizard');

        if (null !== $username) {
            $this->io->text(' > <info>Username (unique idendifier)</info>: '.$username);
        } else {
            $input->setArgument('username', $this->io->ask(ucfirst($this->metadata->findFieldByType(UserMetadata::TYPE_USERNAME))));
        }

        if (null !== $password) {
            $this->io->text(' > <info>Password</info>: ******');
        } else {
            $input->setArgument('password', $this->io->askHidden('Password (your type will be hidden)'));
        }

        $answers = [];
        foreach ($fields as $field) {
            $question = new Question($field);
            $answers[$field] = $this->io->askQuestion($question);
        }

        $input->setOption('answers', json_encode($answers));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $input->getArgument('username');
        $plainPassword = $input->getArgument('password');
        $isAdmin = $input->getOption('admin');
        $fields = $input->getOption('fields') ? explode(',', $input->getOption('fields')) : [];

        $className = $this->metadata->findUserClassName();
        $user = new $className();


        $this->validate($plainPassword, [new Length(['min' => 6]), new NotBlank()], 'password');

        $accessor = PropertyAccess::createPropertyAccessor();
        $accessor->setValue($user, $this->metadata->findFieldByType('username'), $username);
        $accessor->setValue($user, $this->metadata->findFieldByType('password'), $this->encoder->encodePassword($user, $plainPassword));
        $accessor->setValue($user, $this->metadata->findFieldByType('roles'), [$isAdmin ? 'ROLE_ADMIN' : 'ROLE_USER']);

        foreach ($fields as $field) {
            $answers = json_decode($input->getOption('answers'), 1);
            if (!is_array($answers)) {
                throw new \InvalidArgumentException('invalid answers - must be a valid json string');
            }
            $accessor->setValue($user, $field, $answers[$field]);
        }

        $violation = $this->validator->validate($user);
        if ($violation->count()) {
            throw new \Exception((string) $violation);
        }

        $this->em->persist($user);
        $this->em->flush();

        $id = $accessor->getValue($user, 'id');

        $this->io->success(sprintf('%s was successfully created: %s - with ID: %s', $isAdmin ? 'Admin user' : 'User', $username, $id));

        return 0;
    }

    private function getCommandHelp(): string
    {
        return <<<'HELP'
The <info>%command.name%</info> command creates new users and saves them in the database:

  <info>php %command.full_name%</info> <comment>username password</comment>

By default the command creates regular users. To create administrator users,
add the <comment>--admin</comment> option:

  <info>php %command.full_name%</info> username password <comment>--admin</comment>

If you omit any of the required arguments, the command will ask you to
provide the missing values:

  # command will ask you for the password
  <info>php %command.full_name%</info> <comment>username</comment>

HELP;
    }

    private function validate($value, $constraints = null, string $name = null)
    {
        $violation = $this->validator->validate($value, $constraints);
        if ($violation->count()) {
            throw new \Exception(($name ? $name .' ' : '') . (string) $violation);
        }
    }
}