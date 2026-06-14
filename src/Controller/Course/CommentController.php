<?php

namespace App\Controller\Course;

use App\Entity\Comment;
use App\Entity\CommentLike;
use App\Entity\Courses;
use App\Entity\User;
use App\Form\CommentFormType;
use App\Repository\CommentLikeRepository;
use App\Repository\CommentRepository;
use App\Security\Voter\CommentVoter;
use App\Security\Voter\CourseVoter;
use App\Services\CommentReportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class CommentController extends AbstractController
{
    public function __construct(
        private readonly CommentRepository $commentRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/course/comment/{id}', name: 'app_course_comment_create', methods: ['POST'])]
    public function create(Courses $course, Request $request): Response
    {
        $this->denyAccessUnlessGranted(CourseVoter::VIEW, $course, "Vous n'avez pas accès à ce cours.");

        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Vous devez être connecté pour commenter.');
        }

        $comment = new Comment();
        $form = $this->createForm(CommentFormType::class, $comment);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', 'Votre commentaire ne peut pas être vide.');

            return $this->redirectToCourse($course);
        }

        $parentId = (string) $form->get('parent')->getData();
        $parent = null;

        if ($parentId !== '') {
            $parent = $this->commentRepository->find((int) $parentId);

            if (!$parent instanceof Comment || $parent->getCourse() !== $course || !$parent->isRoot()) {
                throw $this->createAccessDeniedException('La réponse demandée est invalide.');
            }

            if ($parent->getUser()?->getId() === $user->getId()) {
                $this->addFlash('warning', 'Vous ne pouvez pas répondre à votre propre commentaire.');

                return $this->redirectToCourse($course);
            }
        }

        if ($parent === null && $this->commentRepository->findRootByUserAndCourse($user, $course) instanceof Comment) {
            $this->addFlash('warning', 'Vous avez déjà publié un commentaire principal pour ce cours. Vous pouvez le modifier ou répondre à un commentaire d’un autre utilisateur.');

            return $this->redirectToCourse($course);
        }

        $comment
            ->setCourse($course)
            ->setUser($user)
            ->setParent($parent);

        $this->entityManager->persist($comment);
        $this->entityManager->flush();

        $this->addFlash('success', 'Votre commentaire a bien été publié.');

        return $this->redirectToCourse($course);
    }

    #[Route('/comments/{id}/report', name: 'app_comments_report', methods: ['POST'])]
    public function report(Comment $comment, Request $request, CommentReportService $commentReportService): Response
    {
        $this->denyAccessUnlessGranted(CourseVoter::VIEW, $comment->getCourse(), "Vous n'avez pas accès à ce cours.");

        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Vous devez être connecté pour signaler un commentaire.');
        }

        if (!$this->isCsrfTokenValid('comment_report_' . $comment->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Le jeton CSRF est invalide.');
        }

        if ($comment->getUser()?->getId() === $user->getId()) {
            $this->addFlash('warning', 'Vous ne pouvez pas signaler votre propre commentaire.');

            return $this->redirectToCourse($comment->getCourse());
        }

        if ($comment->isHidden()) {
            $this->addFlash('info', 'Ce commentaire a déjà été masqué par la modération.');

            return $this->redirectToCourse($comment->getCourse());
        }

        $emailSent = $commentReportService->report($comment, $user);

        if ($emailSent === null) {
            $this->addFlash('info', 'Vous avez déjà signalé ce commentaire.');

            return $this->redirectToCourse($comment->getCourse());
        }

        $this->addFlash(
            $emailSent ? 'success' : 'warning',
            $emailSent
                ? 'Merci, le commentaire a été signalé à l’administration.'
                : 'Le signalement a été enregistré, mais l’email d’alerte n’a pas pu être envoyé.'
        );

        return $this->redirectToCourse($comment->getCourse());
    }

    #[Route('/comments/{id}/like', name: 'app_comments_like', methods: ['POST'])]
    public function like(Comment $comment, Request $request, CommentLikeRepository $commentLikeRepository): JsonResponse
    {
        $this->denyAccessUnlessGranted(CourseVoter::VIEW, $comment->getCourse(), "Vous n'avez pas accès à ce cours.");

        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Vous devez être connecté pour aimer un commentaire.');
        }

        if (!$this->isCsrfTokenValid('comment_like_' . $comment->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Le jeton CSRF est invalide.');
        }

        if ($comment->isHidden()) {
            return $this->json([
                'message' => 'Ce commentaire a été masqué.',
                'likeCount' => $commentLikeRepository->countByComment($comment),
                'liked' => false,
            ], Response::HTTP_GONE);
        }

        if ($comment->getUser()?->getId() === $user->getId()) {
            return $this->json([
                'message' => 'Vous ne pouvez pas aimer votre propre commentaire.',
                'likeCount' => $commentLikeRepository->countByComment($comment),
                'liked' => false,
            ], Response::HTTP_FORBIDDEN);
        }

        $like = $commentLikeRepository->findOneByCommentAndUser($comment, $user);
        $liked = false;

        if ($like instanceof CommentLike) {
            $this->entityManager->remove($like);
        } else {
            $like = (new CommentLike())
                ->setComment($comment)
                ->setUser($user);

            $this->entityManager->persist($like);
            $liked = true;
        }

        $this->entityManager->flush();

        return $this->json([
            'message' => $liked ? 'Commentaire aimé.' : 'Like retiré.',
            'likeCount' => $commentLikeRepository->countByComment($comment),
            'liked' => $liked,
        ]);
    }

    #[Route('/comments/{id}/edit', name: 'app_comments_edit', methods: ['POST'])]
    public function edit(Comment $comment, Request $request): Response
    {
        $this->denyAccessUnlessGranted(CommentVoter::EDIT, $comment, "Vous n'êtes pas l'auteur de ce commentaire.");
        $this->denyAccessUnlessGranted(CourseVoter::VIEW, $comment->getCourse(), "Vous n'avez plus accès à ce cours.");

        if (!$this->isCsrfTokenValid('comment_edit_' . $comment->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Le jeton CSRF est invalide.');
        }

        $content = trim((string) $request->request->get('content'));

        if ($content === '') {
            $this->addFlash('danger', 'Un commentaire ne peut pas être vide.');

            return $this->redirectToCourse($comment->getCourse());
        }

        $comment
            ->setContent($content)
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        $this->addFlash('success', 'Le commentaire a bien été modifié.');

        return $this->redirectToCourse($comment->getCourse());
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('/comments/{id}/delete', name: 'app_comments_delete', methods: ['POST'])]
    public function delete(Comment $comment, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('comment_delete_' . $comment->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Le jeton CSRF est invalide.');
        }

        if ($comment->hasReplies()) {
            $this->addFlash('warning', 'Ce commentaire a déjà des réponses : vous pouvez le modifier, mais pas le supprimer.');

            return $this->redirectToCourse($comment->getCourse());
        }

        $course = $comment->getCourse();
        $this->entityManager->remove($comment);
        $this->entityManager->flush();

        $this->addFlash('success', 'Le commentaire a bien été supprimé.');

        return $this->redirectToCourse($course);
    }

    private function redirectToCourse(?Courses $course): RedirectResponse
    {
        if (!$course instanceof Courses) {
            return $this->redirectToRoute('app_home');
        }

        return $this->redirectToRoute('app_course_show', [
            'programSlug' => $course->getSection()?->getProgram()?->getSlug(),
            'sectionSlug' => $course->getSection()?->getSlug(),
            'courseSlug' => $course->getSlug(),
            '_fragment' => 'comments',
        ]);
    }
}
