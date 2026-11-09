<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Core\View;

abstract class Controller
{
    protected function view(string $tpl, array $data = [], string $layout = 'app'): Response
    {
        return Response::html(View::render($tpl, $data, $layout));
    }

    protected function redirect(string $path, ?string $flash = null, string $type = 'success'): Response
    {
        if ($flash !== null) {
            Session::flash($type, $flash);
        }
        return Response::redirect(url($path));
    }

    /** Run an action; on ValidationException flash the errors and redirect back to $back. */
    protected function attempt(callable $fn, string $back): Response
    {
        try {
            return $fn();
        } catch (ValidationException $e) {
            foreach ($e->errors as $err) {
                Session::flash('error', $err);
            }
            return Response::redirect(url($back));
        }
    }
}
