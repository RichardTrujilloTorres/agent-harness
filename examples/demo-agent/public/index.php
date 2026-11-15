<?php

/**
 * "Trattoria Demo": a tiny rule-based restaurant booking bot used to try agent-harness.
 *
 * No dependencies, no API key, fully deterministic.
 * Run: php -S 127.0.0.1:8787 -t examples/demo-agent/public
 *
 * Contract:
 *   POST /chat  {"session_id": "...", "message": "..."}
 *   -> {"reply": "...", "tool_calls": [{"name": "...", "arguments": {...}}], "handoff": false}
 *
 * It contains two deliberate bugs (marked "intentional: demo failure") so that
 * two of the example scenarios fail and show what a failing run looks like.
 *
 * This is a demo, not a production service: bind it to 127.0.0.1 only.
 */

declare(strict_types=1);

const OPENING_HOURS = 'We are open Tuesday to Sunday, 19:00 to 23:00.';
const FALLBACK = 'Sorry, I can only help with bookings and opening hours.';
const QUESTIONS = [
    'party_size' => 'How many people will be dining?',
    'day' => 'Which day would you like to come?',
    'time' => 'What time would you like the table?',
    'name' => 'What name should I put the booking under?',
];
const NUMBER_WORDS = [
    'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6,
    'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12,
];
const DAYS = 'monday|tuesday|wednesday|thursday|friday|saturday|sunday|today|tomorrow';
/** Largest request body accepted, in bytes. */
const MAX_BODY_BYTES = 16_384;

/**
 * @param array<string, mixed> $body
 */
function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo jsonBody($body);
    exit;
}

/**
 * JSON with <, >, &, ' and " escaped as \u00XX, so the body is inert even if a browser renders it as HTML.
 *
 * @param array<string, mixed> $body
 *
 * @psalm-taint-escape html
 * @psalm-taint-escape has_quotes
 */
function jsonBody(array $body): string
{
    return (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

/**
 * The session id is client input: only its hash is used in the file name.
 */
function statePath(string $sessionId): string
{
    return sys_get_temp_dir() . '/trattoria-demo-' . hash('sha256', $sessionId) . '.json';
}

/**
 * @return array{party_size: ?int, day: ?string, time: ?string, name: ?string, asked: ?string}
 */
function loadState(string $sessionId): array
{
    $empty = ['party_size' => null, 'day' => null, 'time' => null, 'name' => null, 'asked' => null];
    $raw = @file_get_contents(statePath($sessionId));
    $data = $raw === false ? null : json_decode($raw, true);

    return is_array($data) ? array_merge($empty, array_intersect_key($data, $empty)) : $empty;
}

/**
 * @param array<string, mixed> $state
 */
function saveState(string $sessionId, array $state): void
{
    file_put_contents(statePath($sessionId), json_encode($state), LOCK_EX);
}

function parseNumber(string $token): ?int
{
    if (ctype_digit($token)) {
        return (int) $token;
    }

    return NUMBER_WORDS[$token] ?? null;
}

function extractPartySize(string $text, bool $askedFor): ?int
{
    $num = '(\d{1,3}|' . implode('|', array_keys(NUMBER_WORDS)) . ')';
    $patterns = [
        '/\b(?:table for|party of|group of|for|we are|we\'re)\s+' . $num . '\b(?!\s*(?:am|pm|:|\.\d|o\'clock))/',
        '/\b' . $num . '\s+(?:people|persons|guests|of us|adults)\b/',
    ];
    if ($askedFor) {
        $patterns[] = '/^\s*' . $num . '\s*$/';
    }
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m) === 1) {
            return parseNumber($m[1]);
        }
    }

    return null;
}

function extractDay(string $text): ?string
{
    return preg_match('/\b(' . DAYS . ')\b/', $text, $m) === 1 ? $m[1] : null;
}

function extractTime(string $text, bool $askedFor): ?string
{
    $hour = null;
    $minute = 0;
    if (preg_match('/\b(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm)\b/', $text, $m) === 1) {
        $hour = (int) $m[1] % 12 + ($m[3] === 'pm' ? 12 : 0);
        $minute = (int) ($m[2] ?? 0);
    } elseif (preg_match('/\b(\d{1,2})[:.](\d{2})\b/', $text, $m) === 1) {
        $hour = (int) $m[1];
        $minute = (int) $m[2];
    } elseif (preg_match('/\bat\s+(\d{1,2})\b/', $text, $m) === 1 || ($askedFor && preg_match('/^\s*(\d{1,2})\s*$/', $text, $m) === 1)) {
        $hour = (int) $m[1];
    }

    if ($hour === null) {
        return null;
    }
    // A dinner-only restaurant: "at 8" means 20:00.
    if ($hour >= 1 && $hour <= 11 && !preg_match('/\bam\b/', $text)) {
        $hour += 12;
    }
    if ($hour > 23 || $minute > 59) {
        return null;
    }

    return sprintf('%02d:%02d', $hour, $minute);
}

function extractName(string $original, bool $askedFor): ?string
{
    if (preg_match('/\b(?:my name is|name is|name\'s|under the name|under)\s+([a-z][a-z\'-]*)/i', $original, $m) === 1) {
        return ucfirst(strtolower($m[1]));
    }
    if ($askedFor) {
        $candidate = trim((string) preg_replace('/^(?:it\'s|it is)\s+/i', '', trim($original, " .!")));
        if (preg_match('/^[a-z][a-z\' -]{0,40}$/i', $candidate) === 1) {
            return ucwords(strtolower($candidate));
        }
    }

    return null;
}

/**
 * @param array{party_size: ?int, day: ?string, time: ?string, name: ?string, asked: ?string} $state
 * @return array{0: array{reply: string, tool_calls: list<array<string, mixed>>, handoff: bool}, 1: array{party_size: ?int, day: ?string, time: ?string, name: ?string, asked: ?string}}
 */
function handle(string $message, array $state): array
{
    $text = strtolower(trim($message));
    $out = ['reply' => FALLBACK, 'tool_calls' => [], 'handoff' => false];

    if (preg_match('/\b(human|real person|speak to someone|staff|manager|operator|complain|complaint)\b/', $text) === 1) {
        $out['reply'] = 'I am passing you to a member of our staff now.';
        $out['handoff'] = true;

        return [$out, $state];
    }

    if (preg_match('/\b(open|opening|hours|close|closing)\b/', $text) === 1) {
        $out['reply'] = OPENING_HOURS;

        return [$out, $state];
    }

    if (preg_match('/(allerg|gluten|coeliac|celiac|vegan|vegetarian|lactose|nuts?\b|menu|dish|ingredient)/', $text) === 1) {
        // intentional: demo failure (the bot should not make claims about allergens; it invents an answer)
        $out['reply'] = 'Yes, all our dishes are gluten-free.';

        return [$out, $state];
    }

    $asked = $state['asked'];
    $found = [
        'party_size' => extractPartySize($text, $asked === 'party_size'),
        'day' => extractDay($text),
        'time' => extractTime($text, $asked === 'time'),
        'name' => extractName(trim($message), $asked === 'name'),
    ];
    $intent = preg_match('/\b(book|booking|reserve|reservation|table)\b/', $text) === 1;
    $anyFound = array_filter($found, static fn(int|string|null $v): bool => $v !== null) !== [];

    if (!$intent && !$anyFound) {
        if ($asked === null && preg_match('/^(hi|hello|hey|good (morning|afternoon|evening))\b/', $text) === 1) {
            $out['reply'] = 'Hello! I can help you book a table or tell you our opening hours.';
        }

        return [$out, $state];
    }

    foreach ($found as $slot => $value) {
        if ($value !== null) {
            $state[$slot] = $value;
        }
    }

    // intentional: demo failure (parties above 8 should be handed off to staff; the bot books them anyway)

    foreach (array_keys(QUESTIONS) as $slot) {
        if ($state[$slot] === null) {
            $state['asked'] = $slot;
            $out['reply'] = QUESTIONS[$slot];

            return [$out, $state];
        }
    }

    $arguments = [
        'party_size' => (int) $state['party_size'],
        'day' => (string) $state['day'],
        'time' => (string) $state['time'],
        'name' => (string) $state['name'],
    ];
    $out['tool_calls'][] = ['name' => 'create_booking', 'arguments' => $arguments];
    $out['reply'] = sprintf(
        'Your table for %d on %s at %s under the name %s is confirmed.',
        $arguments['party_size'],
        ucfirst($arguments['day']),
        $arguments['time'],
        $arguments['name'],
    );

    $reset = ['party_size' => null, 'day' => null, 'time' => null, 'name' => null, 'asked' => null];

    return [$out, $reset];
}

$path = parse_url(is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
if ($path !== '/chat') {
    respond(404, ['error' => 'Not found. Use POST /chat.']);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    respond(405, ['error' => 'Method not allowed. Use POST /chat.']);
}
$contentType = is_string($_SERVER['CONTENT_TYPE'] ?? null) ? strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'])[0])) : '';
if ($contentType !== 'application/json') {
    respond(415, ['error' => 'Content-Type must be application/json.']);
}
$declared = $_SERVER['CONTENT_LENGTH'] ?? null;
if (is_numeric($declared) && (int) $declared > MAX_BODY_BYTES) {
    respond(413, ['error' => sprintf('Body larger than %d bytes.', MAX_BODY_BYTES)]);
}
$raw = file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
if ($raw === false || strlen($raw) > MAX_BODY_BYTES) {
    respond(413, ['error' => sprintf('Body larger than %d bytes.', MAX_BODY_BYTES)]);
}

$input = json_decode($raw, true);
if (!is_array($input) || !is_string($input['session_id'] ?? null) || $input['session_id'] === '' || !is_string($input['message'] ?? null)) {
    respond(400, ['error' => 'Body must be JSON: {"session_id": string, "message": string}.']);
}

$state = loadState($input['session_id']);
[$out, $state] = handle($input['message'], $state);
saveState($input['session_id'], $state);

respond(200, $out);
