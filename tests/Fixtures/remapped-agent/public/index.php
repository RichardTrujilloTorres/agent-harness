<?php

/**
 * Fixture agent with a non-default contract, to prove field remapping end to end.
 *
 * Request:  {"conversation": {"id": "..."}, "input": "..."}
 * Response: {"data": {"output": {"text": "..."}, "actions": [{"tool": "...", "params": "{json}"}], "escalate": bool}}
 */

declare(strict_types=1);

header('Content-Type: application/json');

$in = json_decode((string) file_get_contents('php://input'), true);
$conversation = is_array($in) ? $in['conversation'] ?? null : null;
if (!is_array($in) || !is_array($conversation) || !is_string($conversation['id'] ?? null) || !is_string($in['input'] ?? null)) {
    http_response_code(400);
    echo json_encode(['error' => 'expected {"conversation": {"id": string}, "input": string}']);
    exit;
}

$text = strtolower($in['input']);
$actions = [];
$escalate = false;
$reply = 'Echo: ' . $in['input'];

if (str_contains($text, 'order')) {
    $actions[] = ['tool' => 'lookup_order', 'params' => json_encode(['order_id' => 42, 'customer' => ['email' => 'test@example.com']])];
    $reply = 'Order 42 is on its way.';
} elseif (str_contains($text, 'agent')) {
    $escalate = true;
    $reply = 'Connecting you to an agent.';
}

echo json_encode(['data' => ['output' => ['text' => $reply], 'actions' => $actions, 'escalate' => $escalate]]);
