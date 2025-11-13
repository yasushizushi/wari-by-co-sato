<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function send_json($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function send_error(string $message, int $status = 400, array $extra = []): void
{
    $payload = array_merge(['message' => $message], $extra);
    send_json($payload, $status);
}

function get_request_data(): array
{
    $input = file_get_contents('php://input');
    if ($input === false || $input === '') {
        return [];
    }

    $data = json_decode($input, true);
    if ($data === null) {
        send_error('入力内容を確認できませんでした。', 400);
    }

    return $data;
}

function generate_group_code(PDO $pdo, int $length = 8): string
{
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }

        $stmt = $pdo->prepare('SELECT id FROM groups WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
    } while ($stmt->fetch());

    return $code;
}

function find_group_by_code(PDO $pdo, string $code): array
{
    $stmt = $pdo->prepare('SELECT * FROM groups WHERE code = :code LIMIT 1');
    $stmt->execute(['code' => $code]);
    $group = $stmt->fetch();

    if (!$group) {
        send_error('グループが見つかりませんでした。コードを確かめてね。', 404);
    }

    return $group;
}

function fetch_members(PDO $pdo, int $group_id): array
{
    $stmt = $pdo->prepare('SELECT id, name, role, default_ratio, created_at FROM members WHERE group_id = :group_id ORDER BY created_at ASC');
    $stmt->execute(['group_id' => $group_id]);
    return $stmt->fetchAll();
}
