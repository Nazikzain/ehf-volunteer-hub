<?php
declare(strict_types=1);

namespace App\Core;

use App\Audit\AuditLogger;
use App\Security\AccessDenied;
use App\Security\Auth;
use App\Security\Gate;

/**
 * One pipeline for every request (Design Rev3 §8.1):
 * Authenticate -> CSRF (state-changing) -> Authorise(permission) -> Scope (in services).
 */
final class Kernel
{
    public function __construct(private Router $router)
    {
    }

    public function handle(Request $req): Response
    {
        AuditLogger::$requestId = $req->id;
        AuditLogger::$ip = $req->ip();
        Session::start();
        Auth::loadFromSession();
        View::$shared = ['request' => $req];
        try {
            $match = $this->router->match($req->method, $req->path);
            if ($match === null) {
                throw new HttpException(404);
            }
            [$route, $params] = $match;
            $req->params = $params;
            if ($req->method === 'POST' && !Csrf::valid($req)) {
                AuditLogger::record('csrf.rejected', 'denied', null, null, ['route' => $route['pattern']]);
                return $this->error($req, 403, 'Your session has expired or the form was not valid. Please try again.');
            }
            if ($route['opts']['auth'] && !Auth::check()) {
                if ($req->wantsJson()) {
                    return Response::json(['error' => 'unauthenticated'], 401);
                }
                Session::set('intended', $req->method === 'GET' ? $req->path : null);
                return Response::redirect(url('/login'));
            }
            if ($route['opts']['perm'] !== null) {
                Gate::authorize($route['opts']['perm']);
            }
            [$class, $method] = $route['handler'];
            $res = (new $class())->$method($req);
            return $res instanceof Response ? $res : Response::html((string) $res);
        } catch (AccessDenied $e) {
            Gate::logDenial($e, $req->method . ' ' . $req->path);
            return $this->error($req, $e->status);
        } catch (HttpException $e) {
            return $this->error($req, $e->status);
        } catch (\Throwable $e) {
            ErrorHandler::log($e, $req->id);
            return $this->error($req, 500);
        }
    }

    private function error(Request $req, int $status, ?string $message = null): Response
    {
        $messages = [403 => 'You do not have permission to do that.', 404 => 'The page you requested could not be found.',
            405 => 'That action is not allowed.', 500 => 'Something went wrong on our side. The problem has been logged.'];
        $msg = $message ?? ($messages[$status] ?? 'Error');
        if ($req->wantsJson()) {
            return Response::json(['error' => $msg, 'request_id' => $req->id], $status);
        }
        return Response::html(View::render('errors/error', ['status' => $status, 'message' => $msg, 'title' => "Error {$status}"],
            Auth::check() ? 'app' : 'public'), $status);
    }
}
