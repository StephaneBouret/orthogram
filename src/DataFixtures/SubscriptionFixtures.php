<?php

namespace App\DataFixtures;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SubscriptionStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class SubscriptionFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public static function getGroups(): array
    {
        return ['subscription'];
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var User $user0 */
        $user0 = $this->getReference('user_0', User::class);

        /** @var User $user1 */
        $user1 = $this->getReference('user_1', User::class);

        /** @var User $user2 */
        $user2 = $this->getReference('user_2', User::class);

        $today = new \DateTimeImmutable('today');

        $endsAtJ30 = $today->modify('+30 days')->setTime(23, 59, 59);
        $startsAtJ30 = $endsAtJ30->modify('-1 year')->setTime(0, 0, 0);

        $endsAtJ15 = $today->modify('+15 days')->setTime(23, 59, 59);
        $startsAtJ15 = $endsAtJ15->modify('-1 year')->setTime(0, 0, 0);

        $endsAtExpired = $today->modify('-1 day')->setTime(23, 59, 59);
        $startsAtExpired = $endsAtExpired->modify('-1 year')->setTime(0, 0, 0);

        $this->createPaidSubscription(
            manager: $manager,
            user: $user0,
            title: 'Abonnement Orthogram annuel - expire dans 30 jours',
            startsAt: $startsAtJ30,
            endsAt: $endsAtJ30,
            paymentReference: 'TEST_FIXTURE_J30'
        );

        $this->createPaidSubscription(
            manager: $manager,
            user: $user1,
            title: 'Abonnement Orthogram annuel - expire dans 15 jours',
            startsAt: $startsAtJ15,
            endsAt: $endsAtJ15,
            paymentReference: 'TEST_FIXTURE_J15'
        );

        $this->createPaidSubscription(
            manager: $manager,
            user: $user2,
            title: 'Abonnement Orthogram annuel - déjà expiré',
            startsAt: $startsAtExpired,
            endsAt: $endsAtExpired,
            paymentReference: 'TEST_FIXTURE_EXPIRED'
        );

        $manager->flush();
    }

    private function createPaidSubscription(
        ObjectManager $manager,
        User $user,
        string $title,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        string $paymentReference,
    ): void {
        $subscription = new Subscription();

        $subscription
            ->setUser($user)
            ->setEmail((string) $user->getEmail())
            ->setStatus(SubscriptionStatus::ACTIVE)
            ->setPriceCents(30000)
            ->setTitle($title)
            ->setDescription('Abonnement de test généré par fixtures.')
            ->setIsLifetime(false)
            ->setStartsAt($startsAt)
            ->setEndsAt($endsAt)
            ->setPaymentReference($paymentReference)
            ->setTermsAcceptedAt($startsAt)
            ->setImmediateAccessRequestedAt($startsAt)
            ->setWithdrawalRightWaivedAt($startsAt);

        $manager->persist($subscription);
    }
}
