<?php
/**
 * AgriSense - Public landing page.
 *
 * The front door at "/": visitors who are not signed in see what the system
 * does and where to sign in; anyone already signed in goes straight to the
 * dashboard, as they did before this page existed.
 */

class HomeController
{
    public function index(): void
    {
        if (Auth::check()) {
            redirect('dashboard');
        }

        render('landing/index', [], [
            'title' => APP_NAME . ' - ' . APP_TAGLINE,
            'bare'  => true,
        ]);
    }
}
