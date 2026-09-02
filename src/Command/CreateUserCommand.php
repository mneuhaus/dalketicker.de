<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Create or update an admin account for the /admin monitoring area.
 *
 *   bin/console dalketicker:user:create marc@example.com                # fragt das Passwort verdeckt ab
 *   bin/console dalketicker:user:create marc@example.com 'einPasswort'  # nur für Skripte
 */
#[AsCommand(
    name: 'dalketicker:user:create',
    description: 'Legt einen Admin-Login an (oder setzt dessen Passwort neu)',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-Mail / Login')
            // Optional on purpose: a password on the command line ends up in the
            // shell history and in `ps`. Interactive runs ask for it hidden; the
            // argument stays for scripted setups.
            ->addArgument('password', InputArgument::OPTIONAL, 'Passwort (weglassen = verdeckte Abfrage; als Argument nur für Skripte, landet sonst in der Shell-History)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $password = $input->getArgument('password') ?? $io->askHidden('Passwort', static function (?string $value): string {
            if ($value === null || trim($value) === '') {
                throw new \RuntimeException('Das Passwort darf nicht leer sein.');
            }

            return $value;
        });
        if (!\is_string($password) || $password === '') {
            // Non-interactive run (-n / no TTY) without the argument: nothing to hash.
            $io->error('Passwort fehlt: als Argument übergeben oder interaktiv eingeben.');

            return Command::INVALID;
        }

        $user = $this->users->findByEmail($email) ?? new User($email);
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Admin "%s" angelegt/aktualisiert.', $email));

        return Command::SUCCESS;
    }
}
