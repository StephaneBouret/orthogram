<?php

namespace App\Services;

use App\Entity\Avatar;
use App\Entity\User;
use App\Repository\AvatarRepository;
use Doctrine\ORM\EntityManagerInterface;
use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class AvatarService
{
    private const AVATAR_DIRECTORY = 'public/images/avatars';
    private const FONT_PATH = 'assets/fonts/Roboto-Regular.ttf';
    private const DEFAULT_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AvatarRepository $avatarRepository,
        private readonly KernelInterface $kernel,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function createAndAssignAvatar(User $user): Avatar
    {
        $avatar = $user->getAvatar() ?? $this->findExistingAvatar($user) ?? new Avatar();

        if (null === $avatar->getImageName() && null === $avatar->getImageFile()) {
            $this->createDefaultAvatar($avatar, $user);
        }

        $user->setAvatar($avatar);
        $this->em->persist($avatar);

        return $avatar;
    }

    public function handleAvatarForm(FormInterface $avatarForm, User $user, ?Avatar $avatar = null, bool $flush = true): bool
    {
        if (!$avatarForm->isSubmitted() || !$avatarForm->isValid()) {
            return false;
        }

        $formAvatar = $avatarForm->getData();
        $avatar = $formAvatar instanceof Avatar
            ? $this->resolveAvatar($user, $formAvatar)
            : ($avatar ?? $user->getAvatar() ?? $this->findExistingAvatar($user) ?? new Avatar());

        if (null === $avatar->getImageFile() && null === $avatar->getImageName()) {
            $this->createDefaultAvatar($avatar, $user);
        }

        $user->setAvatar($avatar);
        $this->em->persist($avatar);

        if ($flush) {
            $this->em->flush();
            $this->convertStoredImageToWebp($avatar);
        }

        return true;
    }

    public function convertStoredImageToWebp(Avatar $avatar, bool $flush = true): bool
    {
        $imageName = $avatar->getImageName();

        if (null === $imageName || $this->isWebpImage($imageName)) {
            return false;
        }

        $sourcePath = $this->getAvatarDirectory().DIRECTORY_SEPARATOR.$imageName;

        if (!is_file($sourcePath)) {
            return false;
        }

        $webpName = $this->createWebpFilename($imageName);
        $webpPath = $this->getAvatarDirectory().DIRECTORY_SEPARATOR.$webpName;

        (new Imagine())
            ->open($sourcePath)
            ->save($webpPath, [
                'format' => 'webp',
                'quality' => 90,
            ]);

        $this->filesystem->remove($sourcePath);

        $avatar
            ->setImageName($webpName)
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->em->persist($avatar);

        if ($flush) {
            $this->em->flush();
        }

        return true;
    }

    public function hasStoredImage(Avatar $avatar): bool
    {
        $imageName = $avatar->getImageName();

        if (null === $imageName) {
            return false;
        }

        return is_file($this->getAvatarDirectory().DIRECTORY_SEPARATOR.$imageName);
    }

    public function deleteAvatar(Avatar $avatar): void
    {
        $imageName = $avatar->getImageName();

        if (null !== $imageName) {
            $this->filesystem->remove($this->getAvatarDirectory().DIRECTORY_SEPARATOR.$imageName);
        }

        $user = $avatar->getUser();
        if (null !== $user && $user->getAvatar() === $avatar) {
            $user->setAvatar(null);
        }

        $this->em->remove($avatar);
    }

    public function createDefaultAvatar(Avatar $avatar, User $user): void
    {
        $avatarDirectory = $this->getAvatarDirectory();
        $this->filesystem->mkdir($avatarDirectory);

        $fontPath = $this->getFontPath();
        if (!is_file($fontPath)) {
            throw new \RuntimeException(sprintf('Le fichier de police est introuvable : %s', $fontPath));
        }

        $filename = bin2hex(random_bytes(16)).'.webp';
        $outputPath = $avatarDirectory.DIRECTORY_SEPARATOR.$filename;

        $imagine = new Imagine();
        $palette = new RGB();
        $size = new Box(self::DEFAULT_SIZE, self::DEFAULT_SIZE);
        $image = $imagine->create($size, $palette->color('#232323'));

        $initial = $this->getInitial($user);
        $font = $imagine->font($fontPath, 220, $palette->color('#FFFFFF'));
        $textBox = $font->box($initial);

        $x = (int) (($size->getWidth() - $textBox->getWidth()) / 2);
        $y = (int) (($size->getHeight() - $textBox->getHeight()) / 2);

        $image->draw()->text($initial, $font, new Point($x, $y));
        $image->save($outputPath, [
            'format' => 'webp',
            'quality' => 90,
        ]);

        $avatar
            ->setImageName($filename)
            ->setUpdatedAt(new \DateTimeImmutable());
    }

    private function getAvatarDirectory(): string
    {
        return $this->kernel->getProjectDir().DIRECTORY_SEPARATOR.self::AVATAR_DIRECTORY;
    }

    private function getFontPath(): string
    {
        return $this->kernel->getProjectDir().DIRECTORY_SEPARATOR.self::FONT_PATH;
    }

    private function isWebpImage(string $imageName): bool
    {
        return 'webp' === mb_strtolower(pathinfo($imageName, PATHINFO_EXTENSION), 'UTF-8');
    }

    private function createWebpFilename(string $imageName): string
    {
        $filename = pathinfo($imageName, PATHINFO_FILENAME).'.webp';
        $path = $this->getAvatarDirectory().DIRECTORY_SEPARATOR.$filename;

        if (!is_file($path)) {
            return $filename;
        }

        return pathinfo($imageName, PATHINFO_FILENAME).'-'.bin2hex(random_bytes(4)).'.webp';
    }

    private function resolveAvatar(User $user, Avatar $formAvatar): Avatar
    {
        if (null !== $formAvatar->getId()) {
            return $formAvatar;
        }

        $existingAvatar = $this->findExistingAvatar($user);

        if (!$existingAvatar instanceof Avatar) {
            return $formAvatar;
        }

        if (null !== $formAvatar->getImageFile()) {
            $existingAvatar->setImageFile($formAvatar->getImageFile());
        }

        return $existingAvatar;
    }

    private function findExistingAvatar(User $user): ?Avatar
    {
        if (null === $user->getId()) {
            return null;
        }

        return $this->avatarRepository->findOneBy(['user' => $user]);
    }

    private function getInitial(User $user): string
    {
        $firstname = trim((string) $user->getFirstname());

        if ('' === $firstname) {
            return '?';
        }

        return mb_strtoupper(mb_substr($firstname, 0, 1, 'UTF-8'), 'UTF-8');
    }
}
