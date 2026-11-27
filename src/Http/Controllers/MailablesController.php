<?php

namespace Statamic\MailablesViewer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Statamic\MailablesViewer\Mailables;
use Statamic\MailablesViewer\SiteContext;
use Statamic\Sites\Site as SiteInstance;

use function Statamic\trans as __;

class MailablesController
{
    public function preview(Request $request): Response
    {
        $class = $request->query('mailable');

        if (! $class || ! Mailables::contains($class)) {
            abort(404);
        }

        try {
            $html = SiteContext::run(
                $this->site($request),
                fn () => Mailables::make($class, $this->values($request))->render()
            );

            return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
        } catch (\Throwable $e) {
            return response($this->errorPage($e), 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }
    }

    public function meta(Request $request): JsonResponse
    {
        $class = $request->query('mailable');

        if (! $class || ! Mailables::contains($class)) {
            abort(404);
        }

        $described = SiteContext::run(
            $this->site($request),
            fn () => Mailables::describe($class, $this->values($request))
        );

        return response()->json([
            'subject' => $described->subject,
            'from' => $described->from,
            'attachments' => $described->attachments,
            'error' => $described->error,
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
            'mailable' => ['required', 'string', Rule::in(Mailables::classes()->all())],
            'values' => 'array',
        ]);

        SiteContext::run(
            $this->site($request),
            fn () => Mail::to($request->email)->sendNow(Mailables::make($request->mailable, $this->values($request)))
        );

        return back()->withSuccess(__('Test email sent.'));
    }

    protected function site(Request $request): SiteInstance
    {
        return SiteContext::resolve($request->input('site'));
    }

    protected function values(Request $request): array
    {
        $values = $request->input('values', []);

        return is_array($values) ? $values : [];
    }

    protected function errorPage(\Throwable $e): string
    {
        $message = e($e->getMessage());

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Preview Error</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; font-size: 0.875rem; line-height: 1.5; margin: 0; padding: 1rem; }
        .alert { display: flex; align-items: flex-start; gap: 0.75rem; padding: 1.25rem 1rem; border: 1px solid #fecaca; border-radius: 0.75rem; background: #fef2f2; color: #991b1b; }
        svg { width: 1.25rem; height: 1.25rem; flex-shrink: 0; opacity: 0.7; }
        h1 { font-size: inherit; margin: -0.125rem 0 0.25rem; }
        p { margin: 0; overflow-wrap: anywhere; }
    </style>
</head>
<body>
    <div class="alert" role="alert">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14" aria-hidden="true"><g stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4.092v3.44M7 9.288v.62M.96 10.269a3.13 3.13 0 0 0 2.753 2.76c1.07.119 2.167.221 3.287.221s2.218-.102 3.287-.222a3.13 3.13 0 0 0 2.753-2.76c.114-1.063.21-2.155.21-3.268s-.096-2.205-.21-3.269a3.13 3.13 0 0 0-2.753-2.76C9.217.853 8.12.75 7 .75S4.782.852 3.713.972A3.13 3.13 0 0 0 .96 3.732C.846 4.794.75 5.886.75 7s.096 2.205.21 3.269"/></g></svg>
        <div>
            <h1>Unable to preview this mailable</h1>
            <p>{$message}</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
