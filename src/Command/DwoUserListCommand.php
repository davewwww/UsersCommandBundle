<?php

namespace Dwo\UserCommandsBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Dwo\UserCommandsBundle\UserMetadata;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PropertyAccess\PropertyAccess;

class DwoUserListCommand extends Command
{
    protected static $defaultName = 'dwo:user:list';

    /** @var EntityManagerInterface */
    private $em;
    /** @var SymfonyStyle */
    private $io;
    /** @var UserMetadata */
    private $userMetadata;
    /** @var ObjectRepository */
    private $repo;

    public function __construct(EntityManagerInterface $em)
    {
        parent::__construct();

        $this->em = $em;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Lists all the existing users')
            ->setHelp($this->getCommandHelp())
            ->addOption('fields', 'f', InputOption::VALUE_REQUIRED, 'show extra fields from user (coma seperated)')
            ->addOption('max-results', null, InputOption::VALUE_OPTIONAL, 'Limits the number of users listed', 50);
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->userMetadata = new UserMetadata($this->em);
        $this->repo = $this->em->getRepository($this->userMetadata->findUserClassName());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fields = explode(',', $input->getOption('fields'));
        $maxResults = $input->getOption('max-results');

        $idField = $this->userMetadata->findFieldByType('id');

        $accessor = PropertyAccess::createPropertyAccessor();

        $users = [];
        foreach ($this->repo->findBy([], [$idField => 'DESC'], $maxResults) as $user) {
            $userData = [
                $idField   => $accessor->getValue($user, $idField),
                'username' => $user->getUsername(),
                'roles'    => implode(', ', $user->getRoles()),
            ];
            foreach ($fields as $field) {
                $field = trim($field);
                if (!empty($field)) {
                    $data = $accessor->getValue($user, $field);
                    $userData[$field] = is_scalar($data) ? $data : json_encode($data);
                }
            }

            $users[] = $userData;
        }

        $this->io->table(array_keys(current($users)), $users);

        return 0;
    }

    private function getCommandHelp(): string
    {
        return <<<'HELP'
The <info>%command.name%</info> command lists all the users registered in the application:
  <info>php %command.full_name%</info>
By default the command only displays the 50 most recent users. Set the number of
results to display with the <comment>--max-results</comment> option:
  <info>php %command.full_name%</info> <comment>--max-results=2000</comment>
HELP;
    }
}