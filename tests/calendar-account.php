<?php

declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

use App\Command\CreateUserCommand;
use App\Entity\Role;
use App\Entity\User;
use App\Service\UserService;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasher;
use Symfony\Component\Validator\Validation;

class CalendarTestRoles extends EntityRepository
{
    public function __construct() {}
    public function findAll(): array
    {
        $reservation = new Role(); $reservation->setRole('ROLE_RESERVATIONS');
        $admin = new Role(); $admin->setRole('ROLE_ADMIN');
        return [$reservation, $admin];
    }
}

class CalendarTestManager extends EntityManager
{
    public array $saved = [];
    public function __construct() {}
    public function getRepository(string $className): EntityRepository { return new CalendarTestRoles(); }
    public function persist(object $object): void { $this->saved[] = $object; }
    public function flush(): void {}
}

class CalendarTestUsers extends UserService
{
    public bool $validated = false;
    public function __construct() {}
    public function isUsernameAvailable(string $username): bool { return $username !== 'existing-user'; }
    public function isPasswordValid(string $password, User $user, ?FormInterface $form = null, $pwField = 'password'): bool
    {
        $this->validated = true;
        return true;
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$manager = new CalendarTestManager();
$users = new CalendarTestUsers();
$hasher = new UserPasswordHasher(new PasswordHasherFactory([User::class => ['algorithm' => 'auto']]));
$command = new CreateUserCommand(Validation::createValidator(), $manager, $hasher, $users);
$application = new Application();
$application->addCommand($command);
$tester = new CommandTester($command);
$password = 'test-only-password-7841';
$tester->setInputs(['castlehouse-calendar', $password, 'Calendar', 'Connection', 'calendar@example.test', 'ROLE_RESERVATIONS']);
$exitCode = $tester->execute([]);

check(0 === $exitCode, 'The account command failed.');
check($users->validated, 'The command bypassed FewohBee password validation.');
check(1 === count($manager->saved), 'The command must create exactly one account.');
$user = $manager->saved[0];
check($user instanceof User && $user->getActive(), 'The connection account is not active.');
check($user->getUsername() === 'castlehouse-calendar', 'The command changed the username.');
check(in_array('ROLE_RESERVATIONS', $user->getRoles(), true) && !in_array('ROLE_ADMIN', $user->getRoles(), true),
      'The connection account received unexpected permissions.');
check($user->getPassword() !== $password && $hasher->isPasswordValid($user, $password),
      'The command did not use the framework password hasher.');
check(!str_contains($tester->getDisplay(), $password), 'The password was printed by the account command.');

echo "PASS: actual account wizard creates one reservation account, delegates validation, hashes its password, and does not print it. No database, network or live accounts used.\n";
