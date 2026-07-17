<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Models\WhatsappInstance;
use App\Support\Whatsapp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read + send the workspace's saved message templates over the REST API.
 *
 * Other apps (e.g. the connect CRM) list templates here and ask Eagle to send
 * one to a single recipient. Eagle renders the template's merge tags/spintax and
 * dispatches through the chosen connected device via Evolution.
 */
class TemplateController extends Controller
{
    /**
     * List the workspace's templates for a caller to pick from.
     */
    public function index(): JsonResponse
    {
        $templates = Template::query()
            ->latest()
            ->get(['id', 'name', 'type', 'body'])
            ->map(fn (Template $t) => [
                'id'        => $t->id,
                'name'      => $t->name,
                'type'      => $t->type,
                'content'   => (string) $t->body,
                'variables' => $this->mergeTags((string) $t->body),
            ]);

        return response()->json(['data' => $templates]);
    }

    /**
     * Render a template and send it to one recipient from a chosen device.
     */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id'   => ['required', 'integer'],
            'phone'       => ['required', 'string', 'max:32'],
            'template_id' => ['required', 'integer'],
            'variables'   => ['sometimes', 'array'],
        ]);

        $device = WhatsappInstance::find($data['device_id']); // scoped to the token's workspace

        if (! $device) {
            return response()->json(['message' => 'Device not found.'], 404);
        }
        if (! $device->isConnected()) {
            return response()->json(['message' => 'Device is not connected.'], 422);
        }

        $template = Template::find($data['template_id']); // scoped to the token's workspace

        if (! $template) {
            return response()->json(['message' => 'Template not found.'], 404);
        }

        $number  = preg_replace('/\D+/', '', $data['phone']);
        $message = $this->render((string) $template->body, (array) ($data['variables'] ?? []), $number);

        $gateway = Whatsapp::forInstance($device);

        // Media templates: send the media with the rendered body as caption.
        if ($template->type === 'media' && $template->media_url) {
            $result = $gateway->sendMedia(
                $device->instance_name,
                $number,
                $template->media_type ?: 'image',
                $template->media_url,
                $message !== '' ? $message : null,
            );
        } else {
            if ($message === '') {
                return response()->json(['message' => 'Rendered template is empty.'], 422);
            }

            $result = $gateway->sendText($device->instance_name, $number, $message);
        }

        return response()->json(
            $result['ok']
                ? ['ok' => true, 'message_id' => $result['message_id']]
                : ['ok' => false, 'error' => $result['error']],
            $result['ok'] ? 200 : 422,
        );
    }

    /**
     * Resolve spintax + merge tags against the supplied variables.
     *
     * {a|b} -> first option (deterministic for a single API send), {{name}} /
     * {{phone}} / {{date}} plus any caller-supplied variable keys; unknown
     * {{tokens}} collapse to blank so nothing leaks into the message.
     */
    private function render(string $body, array $variables, string $number): string
    {
        // 1) Spintax: pick the first option (single deterministic send).
        $text = preg_replace_callback('/\{([^{}|]*(?:\|[^{}|]*)+)\}/', function ($m) {
            return explode('|', $m[1])[0];
        }, $body);

        // 2) Built-in tags.
        $name = trim((string) ($variables['name'] ?? '')) ?: 'there';
        $text = preg_replace(
            ['/\{\{\s*name\s*\}\}/i', '/\{\{\s*phone\s*\}\}/i', '/\{\{\s*date\s*\}\}/i'],
            [$name, $number, now()->format('M j, Y')],
            $text
        );

        // 3) Caller-supplied variables ({{key}}), unknown tokens -> blank.
        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function ($m) use ($variables) {
            $key = strtolower($m[1]);
            foreach ($variables as $k => $v) {
                if (strtolower((string) $k) === $key && ! is_array($v)) {
                    return (string) $v;
                }
            }

            return '';
        }, $text);
    }

    /**
     * Extract the distinct {{merge_tag}} names from a template body.
     *
     * @return array<int, string>
     */
    private function mergeTags(string $body): array
    {
        preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $body, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1] ?? [])));
    }
}
