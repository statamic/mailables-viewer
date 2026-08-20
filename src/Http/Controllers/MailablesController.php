<?php

namespace Statamic\MailablesViewer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Statamic\MailablesViewer\Mailables;

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
            return response(Mailables::make($class, $this->values($request))->render())
                ->header('Content-Type', 'text/html; charset=UTF-8');
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

        $described = Mailables::describe($class, $this->values($request));

        return response()->json([
            'subject' => $described['subject'],
            'from' => $described['from'],
            'attachments' => $described['attachments'],
            'error' => $described['error'],
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
            'mailable' => ['required', 'string', Rule::in(Mailables::classes()->all())],
            'values' => 'array',
        ]);

        Mail::to($request->email)->sendNow(Mailables::make($request->mailable, $this->values($request)));

        return back()->withSuccess(__('Test email sent.'));
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
        body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 0; padding: 2rem; color: #3d4452; background: #f7f9fc; }
        h1 { font-size: 1.125rem; margin: 0 0 0.5rem; }
        p { margin: 0; line-height: 1.5; }
    </style>
</head>
<body>
    <h1>Unable to preview this mailable</h1>
    <p>{$message}</p>
</body>
</html>
HTML;
    }
}
