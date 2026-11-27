<!-- statamic:hide -->
# Mailables Viewer

> Preview Laravel mailables from the Statamic Control Panel.

![Mailables Viewer](screenshot.jpg)
<!-- /statamic:hide -->

## Features

- Discovers concrete `Illuminate\Mail\Mailable` classes in `app/Mail`
- Renders a live HTML preview
- Injects sample constructor data (scalars, Eloquent models, Statamic users/entries/forms)
- Lets you edit strings, numbers, bools, and datetimes and see the preview update
- Shows subject, from, attachments, template, queue status, and where the mailable is referenced
- Sends a test email with `sendNow()`
- Opens a chosen mailable directly via `?mailable=`

## Installation

You can install the Mailables Viewer addon via Composer:

```
composer require statamic/mailables-viewer
```

Then visit **Utilities → Mailables** in the Control Panel. Super users see it automatically. Everyone else needs the `access mailables utility` permission.

## Usage

Any concrete mailable in `app/Mail` is discovered automatically.

Register mailables that live elsewhere:

```php
use Statamic\MailablesViewer\Mailables;

Mailables::register(\App\Mail\Billing\InvoiceReady::class);
```

You can pass a class or an array of classes. The `Mailables` facade is also available as a Laravel alias.

### Linking to a mailable

Add `?mailable=` to the utility's URL to open the viewer with that mailable already selected:

```php
cp_route('utilities.mailables', ['mailable' => \App\Mail\OrderShipped::class]);
```

Useful for a "preview this email" button elsewhere in the Control Panel. A class the viewer does not have falls back to the first mailable in the list.

### Constructor data

The viewer instantiates each mailable with sample values so you can preview it without writing a factory.

| Type | What happens |
| --- | --- |
| `string`, `int`, `float`, `bool`, datetimes | Fake sample values you can edit in Details |
| Enums | The first case, with the others to pick from in Details (a backed case shows its value alongside its name) |
| Statamic `User`, `Entry`, `Form`, `Submission` | First available record |
| Eloquent models | Factory `make()`, then `query()->first()`, then `::make()` |
| Anything else | Resolved from the container |

Other objects and arrays stay read-only: an array shows as JSON, and so does a collection, cut at 500 characters; any other object shows its class name. Edit a scalar or pick an enum case and the preview plus envelope (subject, from, attachments) refresh live.

Sample strings are guessed from the parameter name (`email` → `preview@example.com`, `name` → `Jane Doe`, `url` → `https://example.com`).

#### Your own example values

Where the sample values fall short, such as an array of order lines that would otherwise be empty, a mailable can name its own. Implement `HasPreviewExamples` and return the values keyed by constructor parameter name:

```php
use Statamic\MailablesViewer\Contracts\HasPreviewExamples;

class OrderShipped extends Mailable implements HasPreviewExamples
{
    public function __construct(
        public string $customer,
        public array $lines = [],
    ) {}

    public static function previewExamples(): array
    {
        return [
            'lines' => [['sku' => 'A-1', 'quantity' => 2]],
        ];
    }
}
```

An example wins over the parameter's default and the guessed sample; a value you edit in Details (scalars, datetimes and enums) wins over the example. Parameters you leave out are filled as before. In Details, a value that comes from `previewExamples()` carries an "Example from code" badge until you edit it. `previewExamples()` is called once per request.

### Sending tests

Pick a mailable, optionally tweak the editable values, enter an address, and send. Tests use `sendNow()` so queued mailables still go out immediately.

## Requirements

- PHP 8.2+
- Statamic 6
