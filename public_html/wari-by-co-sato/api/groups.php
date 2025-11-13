<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'create_group':
        handle_create_group();
        break;
    case 'get_group':
        handle_get_group();
        break;
    case 'add_member':
        handle_add_member();
        break;
    default:
        send_error('この操作は対応していません。', 404);
}

function handle_create_group(): void
{
    $data = get_request_data();
    $name = isset($data['name']) ? trim((string)$data['name']) : '';

    if ($name === '') {
        send_error('グループの名前を入れてね。');
    }

    $pdo = get_pdo();
    $code = generate_group_code($pdo);

    $stmt = $pdo->prepare('INSERT INTO groups (code, name, created_at) VALUES (:code, :name, NOW())');
    $stmt->execute([
        'code' => $code,
        'name' => $name,
    ]);

    $group_id = (int)$pdo->lastInsertId();

    send_json([
        'id' => $group_id,
        'code' => $code,
        'name' => $name,
    ], 201);
}

function handle_get_group(): void
{
    $code = isset($_GET['code']) ? trim((string)$_GET['code']) : '';
    if ($code === '') {
        send_error('コードが必要です。');
    }

    $pdo = get_pdo();
    $group = find_group_by_code($pdo, $code);
    $members = fetch_members($pdo, (int)$group['id']);

    send_json([
        'group' => [
            'id' => (int)$group['id'],
            'code' => $group['code'],
            'name' => $group['name'],
            'created_at' => $group['created_at'],
        ],
        'members' => array_map(function ($member) {
            return [
                'id' => (int)$member['id'],
                'name' => $member['name'],
                'role' => $member['role'],
                'default_ratio' => (float)$member['default_ratio'],
                'created_at' => $member['created_at'],
            ];
        }, $members),
    ]);
}

function handle_add_member(): void
{
    $data = get_request_data();

    $code = isset($data['code']) ? trim((string)$data['code']) : '';
    $name = isset($data['name']) ? trim((string)$data['name']) : '';
    $role = isset($data['role']) ? (string)$data['role'] : 'adult';
    $default_ratio = isset($data['default_ratio']) ? (float)$data['default_ratio'] : 1.0;

    if ($code === '' || $name === '') {
        send_error('コードとお名前を入れてね。');
    }

    if (!in_array($role, ['adult', 'student', 'child'], true)) {
        send_error('区分は「おとな」「学生」「こども」から選んでね。');
    }

    if ($default_ratio <= 0) {
        send_error('負担の目安は0より大きい数字にしてね。');
    }

    $pdo = get_pdo();
    $group = find_group_by_code($pdo, $code);

    $stmt = $pdo->prepare('INSERT INTO members (group_id, name, role, default_ratio, created_at) VALUES (:group_id, :name, :role, :default_ratio, NOW())');
    $stmt->execute([
        'group_id' => $group['id'],
        'name' => $name,
        'role' => $role,
        'default_ratio' => $default_ratio,
    ]);

    send_json([
        'member' => [
            'id' => (int)$pdo->lastInsertId(),
            'name' => $name,
            'role' => $role,
            'default_ratio' => $default_ratio,
        ],
        'message' => 'メンバーを追加しました。'
    ], 201);
}
