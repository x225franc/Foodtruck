<?php

namespace App\Controller;

use App\Entity\Menu;
use App\Entity\User;
use App\Entity\Review;
use App\Entity\Product;
use App\Entity\Category;
use App\Entity\ContactMessage;
use Symfony\Component\Mime\Email;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
// use Symfony\Component\String\Slugger\SluggerInterface;
// use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class AdminController extends AbstractController
{
    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        // Check if the user is logged in and has the role ROLE_ADMIN
        // $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/admin.html.twig');
    }

    #[Route('/admin/user', name: 'admin_user')]
    public function user(EntityManagerInterface $entityManager): Response
    {
        $users = $entityManager->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.roles NOT LIKE :role')
            ->setParameter('role', '%ROLE_ADMIN%')
            ->getQuery()
            ->getResult();

        return $this->render('admin/adminUser.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/admin/ban/{id}', name: 'admin_ban_user')]
    public function banUser(User $user, EntityManagerInterface $entityManager, MailerInterface $mailer): Response
    {
        $user->setRoles(['ROLE_BANNED']);
        $entityManager->flush();

        $email = (new Email())
            ->from($_ENV['MAIL_USER'])
            ->to($user->getEmail())
            ->subject('Votre compte a été banni')
            ->html('<p>Votre compte a été banni.</p>');

        $mailer->send($email);

        $this->addFlash('success', 'Utilisateur banni et notifié.');

        return $this->redirectToRoute('admin_user');
    }

    #[Route('/admin/unban/{id}', name: 'admin_unban_user')]
    public function unbanUser(User $user, EntityManagerInterface $entityManager, MailerInterface $mailer): Response
    {
        $user->setRoles(['ROLE_USER']);
        $entityManager->flush();

        // Send email notification
        $email = (new Email())
            ->from($_ENV['MAIL_USER'])
            ->to($user->getEmail())
            ->subject('Votre compte a été rétabli')
            ->html('<p>Votre compte a été rétabli.</p>');

        $mailer->send($email);

        $this->addFlash('success', 'Utilisateur rétabli et notifié.');

        return $this->redirectToRoute('admin_user');
    }

    #[Route('/admin/category', name: 'admin_category')]
    public function category(Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            if ($request->request->has('add_category')) {
                $category = new Category();
                $category->setTitle($request->request->get('title'));
                $category->setDescription($request->request->get('description'));

                /** @var UploadedFile $imageFile */
                $imageFile = $request->files->get('image');
                if ($imageFile && in_array($imageFile->getMimeType(), ['image/jpeg', 'image/png', 'image/gif'])) {
                    $newFilename = bin2hex(random_bytes(10)) . '.' . $imageFile->guessExtension();

                    try {
                        $imageFile->move(
                            $this->getParameter('images_directory'),
                            $newFilename
                        );
                    } catch (FileException $e) {
                    }

                    $category->setImage($newFilename);
                }

                $entityManager->persist($category);
                $entityManager->flush();
                $this->addFlash('success', 'Catégorie ajoutée avec succès');
            } elseif ($request->request->has('edit_category')) {
                $category = $entityManager->getRepository(Category::class)->find($request->request->get('id'));
                if ($category) {
                    $category->setTitle($request->request->get('title'));
                    $category->setDescription($request->request->get('description'));

                    /** @var UploadedFile $imageFile */
                    $imageFile = $request->files->get('image');
                    if ($imageFile && in_array($imageFile->getMimeType(), ['image/jpeg', 'image/png', 'image/gif'])) {
                        $newFilename = bin2hex(random_bytes(10)) . '.' . $imageFile->guessExtension();

                        try {
                            $imageFile->move(
                                $this->getParameter('images_directory'),
                                $newFilename
                            );
                        } catch (FileException $e) {
                        }

                        $category->setImage($newFilename);
                    }

                    $entityManager->flush();
                    $this->addFlash('success', 'Catégorie modifiée avec succès');
                } else {
                    $this->addFlash('error', 'Catégorie non trouvée');
                }
            } elseif ($request->request->has('delete_category')) {
                $category = $entityManager->getRepository(Category::class)->find($request->request->get('id'));
                if ($category) {
                    $products = $entityManager->getRepository(Product::class)->findBy(['category' => $category]);

                    foreach ($products as $product) {
                        $reviews = $entityManager->getRepository(Review::class)->findBy(['product' => $product]);

                        foreach ($reviews as $review) {
                            $entityManager->remove($review);
                        }

                        if ($product->getImage()) {
                            $imagePath = $this->getParameter('images_directory') . '/' . $product->getImage();
                            if (file_exists($imagePath)) {
                                unlink($imagePath);
                            }
                        }
                        $entityManager->remove($product);
                    }

                    if ($category->getImage()) {
                        $imagePath = $this->getParameter('images_directory') . '/' . $category->getImage();
                        if (file_exists($imagePath)) {
                            unlink($imagePath);
                        }
                    }

                    $entityManager->remove($category);
                    $entityManager->flush();
                    $this->addFlash('success', 'Catégorie et ses produits associés supprimés avec succès');
                } else {
                    $this->addFlash('error', 'Catégorie non trouvée');
                }
            }
        }

        $categories = $entityManager->getRepository(Category::class)->findAll();

        return $this->render('admin/adminCategory.html.twig', [
            'categories' => $categories,
        ]);
    }

    #[Route('/admin/product', name: 'admin_product')]
    public function product(Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            if ($request->request->has('add_product')) {
                $product = new Product();
                $product->setTitle($request->request->get('title'));
                $product->setDescription($request->request->get('description'));
                $product->setPrice($request->request->get('price'));
                $product->setCategory($entityManager->getRepository(Category::class)->find($request->request->get('category_id')));

                /** @var UploadedFile $imageFile */
                $imageFile = $request->files->get('image');
                if ($imageFile && in_array($imageFile->getMimeType(), ['image/jpeg', 'image/png', 'image/gif'])) {
                    $newFilename = bin2hex(random_bytes(10)) . '.' . $imageFile->guessExtension();

                    try {
                        $imageFile->move(
                            $this->getParameter('images_directory'),
                            $newFilename
                        );
                    } catch (FileException $e) {
                    }

                    $product->setImage($newFilename);
                }

                $entityManager->persist($product);
                $entityManager->flush();
                $this->addFlash('success', 'Produit ajouté avec succès');
            } elseif ($request->request->has('edit_product')) {
                $product = $entityManager->getRepository(Product::class)->find($request->request->get('id'));
                if ($product) {
                    $product->setTitle($request->request->get('title'));
                    $product->setDescription($request->request->get('description'));
                    $product->setPrice($request->request->get('price'));
                    $categoryId = $request->request->get('category_id');
                    if ($categoryId) {
                        $category = $entityManager->getRepository(Category::class)->find($categoryId);
                        if ($category) {
                            $product->setCategory($category);
                        } else {
                            $this->addFlash('error', 'Catégorie non trouvée');
                        }
                    }

                    $entityManager->flush();
                    $this->addFlash('success', 'Produit modifié avec succès');
                } else {
                    $this->addFlash('error', 'Produit non trouvé');
                }
            } elseif ($request->request->has('delete_product')) {
                $product = $entityManager->getRepository(Product::class)->find($request->request->get('id'));
                if ($product) {
                    $reviews = $entityManager->getRepository(Review::class)->findBy(['product' => $product]);

                    foreach ($reviews as $review) {
                        $entityManager->remove($review);
                    }

                    if ($product->getImage()) {
                        $imagePath = $this->getParameter('images_directory') . '/' . $product->getImage();
                        if (file_exists($imagePath)) {
                            unlink($imagePath);
                        }
                    }
                    $entityManager->remove($product);
                    $entityManager->flush();
                    $this->addFlash('success', 'Produit et ses avis associés supprimés avec succès');
                } else {
                    $this->addFlash('error', 'Produit non trouvé');
                }
            }
        }

        $products = $entityManager->getRepository(Product::class)->findAll();
        $categories = $entityManager->getRepository(Category::class)->findAll();

        return $this->render('admin/adminProduct.html.twig', [
            'products' => $products,
            'categories' => $categories,
        ]);
    }

    #[Route('/admin/menu', name: 'admin_menu')]
    public function menu(Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            if ($request->request->has('add_menu')) {
                $menu = new Menu();
                $menu->setName($request->request->get('name'));
                $menu->setDescription($request->request->get('description'));

                $productIds = $request->request->all('products');
                foreach ($productIds as $productId) {
                    $product = $entityManager->getRepository(Product::class)->find($productId);
                    if ($product) {
                        $menu->addProduct($product);
                    }
                }

                /** @var UploadedFile $imageFile */
                $imageFile = $request->files->get('image');
                if ($imageFile && in_array($imageFile->getMimeType(), ['image/jpeg', 'image/png', 'image/gif'])) {
                    $newFilename = bin2hex(random_bytes(10)) . '.' . $imageFile->guessExtension();

                    try {
                        $imageFile->move(
                            $this->getParameter('images_directory'),
                            $newFilename
                        );
                    } catch (FileException $e) {
                    }

                    $menu->setImage($newFilename);
                }

                $entityManager->persist($menu);
                $entityManager->flush();
                $this->addFlash('success', 'Menu ajouté avec succès');
            } elseif ($request->request->has('edit_menu')) {
                $menu = $entityManager->getRepository(Menu::class)->find($request->request->get('id'));
                if ($menu) {
                    $menu->setName($request->request->get('name'));
                    $menu->setDescription($request->request->get('description'));

                    $menu->getProducts()->clear();
                    $productIds = $request->request->all('products');
                    foreach ($productIds as $productId) {
                        $product = $entityManager->getRepository(Product::class)->find($productId);
                        if ($product) {
                            $menu->addProduct($product);
                        }
                    }

                    /** @var UploadedFile $imageFile */
                    $imageFile = $request->files->get('image');
                    if ($imageFile && in_array($imageFile->getMimeType(), ['image/jpeg', 'image/png', 'image/gif'])) {
                        $newFilename = bin2hex(random_bytes(10)) . '.' . $imageFile->guessExtension();

                        try {
                            $imageFile->move(
                                $this->getParameter('images_directory'),
                                $newFilename
                            );
                        } catch (FileException $e) {
                        }

                        $menu->setImage($newFilename);
                    }

                    $entityManager->flush();
                    $this->addFlash('success', 'Menu modifié avec succès');
                } else {
                    $this->addFlash('error', 'Menu non trouvé');
                }
            } elseif ($request->request->has('delete_menu')) {
                $menu = $entityManager->getRepository(Menu::class)->find($request->request->get('id'));
                if ($menu) {
                    if ($menu->getImage()) {
                        $imagePath = $this->getParameter('images_directory') . '/' . $menu->getImage();
                        if (file_exists($imagePath)) {
                            unlink($imagePath);
                        }
                    }

                    $entityManager->remove($menu);
                    $entityManager->flush();
                    $this->addFlash('success', 'Menu supprimé avec succès');
                } else {
                    $this->addFlash('error', 'Menu non trouvé');
                }
            }
        }

        $menus = $entityManager->getRepository(Menu::class)->findAll();
        $products = $entityManager->getRepository(Product::class)->findAll();

        return $this->render('admin/adminMenu.html.twig', [
            'menus' => $menus,
            'products' => $products,
        ]);
    }

    #[Route('/admin/review', name: 'admin_review')]
    public function review(Request $request, EntityManagerInterface $entityManager, MailerInterface $mailer): Response
    {
        if ($request->isMethod('POST') && $request->request->has('delete_review')) {
            $review = $entityManager->getRepository(Review::class)->find($request->request->get('id'));

            if ($review) {
                $user = $review->getUser();
                $email = (new Email())
                    ->from($_ENV['MAIL_USER'])
                    ->to($user->getEmail())
                    ->subject('Votre avis a été supprimé')
                    ->html('<p>Votre avis sur notre site a été supprimé car il ne respecte pas nos conditions d\'utilisation.</p>');

                $mailer->send($email);

                $entityManager->remove($review);
                $entityManager->flush();

                $this->addFlash('success', 'Avis supprimé et utilisateur notifié');
            }
        }

        $reviews = $entityManager->getRepository(Review::class)->findAll();

        return $this->render('admin/adminReview.html.twig', [
            'reviews' => $reviews,
        ]);
    }

    #[Route('/admin/contact', name: 'admin_contact')]
    public function contact(EntityManagerInterface $entityManager): Response
    {
        $contacts = $entityManager->getRepository(ContactMessage::class)->findAll();

        return $this->render('admin/adminContact.html.twig', [
            'contacts' => $contacts,
        ]);
    }
}
