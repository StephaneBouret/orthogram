<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture implements FixtureGroupInterface
{
    public function __construct(protected UserPasswordHasherInterface $passwordHasher) {}

    public static function getGroups(): array
    {
        return ['user'];
    }

    public const ADMIN_USER_REFERENCE = 'admin_user';

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');
        $phoneNumberUtil = PhoneNumberUtil::getInstance();

        $admin = new User();
        $hash = $this->passwordHasher->hashPassword($admin, "password");

        $adminRawPhoneNumber = $faker->mobileNumber();
        $adminPhoneNumberObject = $phoneNumberUtil->parse($adminRawPhoneNumber, 'FR');

        $admin->setEmail("admin@gmail.com")
            ->setFirstname("Admin")
            ->setLastname("Admin")
            ->setRoles(['ROLE_ADMIN'])
            ->setAddress($faker->streetAddress())
            ->setPostalCode($faker->postcode())
            ->setCity($faker->city)
            ->setPhone($adminPhoneNumberObject)
            ->setPassword($hash);

        $manager->persist($admin);

        $this->addReference(self::ADMIN_USER_REFERENCE, $admin);

        $users = [];
        for ($u = 0; $u < 3; $u++) {
            $user = new User();
            $hash = $this->passwordHasher->hashPassword($user, "password");

            $rawPhoneNumber = $faker->mobileNumber();
            $phoneNumberObject = $phoneNumberUtil->parse($rawPhoneNumber, 'FR');

            $user->setEmail("user$u@gmail.com")
                ->setFirstname($faker->firstName())
                ->setLastname($faker->lastName())
                ->setAddress($faker->streetAddress())
                ->setPostalCode($faker->postcode())
                ->setCity($faker->city)
                ->setPhone($phoneNumberObject)
                ->setPassword($hash);

            $manager->persist($user);
            $this->addReference('user_' . $u, $user);
            $users[] = $user;
        }

        $manager->flush();
    }
}
