<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'add_item':
        handle_add_item();
        break;
    case 'list':
        handle_list_items();
        break;
    default:
        send_error('この操作は対応していません。', 404);
}

function handle_add_item(): void
{
    $data = get_request_data();

    $code = isset($data['code']) ? trim((string)$data['code']) : '';
    $title = isset($data['title']) ? trim((string)$data['title']) : '';
    $amount = isset($data['amount']) ? (int)$data['amount'] : 0;
    $payer_member_id = isset($data['payer_member_id']) ? (int)$data['payer_member_id'] : 0;
    $participant_ids = isset($data['participant_ids']) ? (array)$data['participant_ids'] : [];

    if ($code === '' || $title === '') {
        send_error('コードと内容を入れてね。');
    }

    if ($amount <= 0) {
        send_error('金額は1円以上で入れてね。');
    }

    if ($payer_member_id <= 0) {
        send_error('払った人を選んでね。');
    }

    $participant_ids = array_values(array_unique(array_map('intval', $participant_ids)));

    if (empty($participant_ids)) {
        send_error('参加した人を選んでね。');
    }

    $pdo = get_pdo();
    $group = find_group_by_code($pdo, $code);

    // 確認: 払った人がグループに属しているか
    $stmt = $pdo->prepare('SELECT id FROM members WHERE id = :id AND group_id = :group_id');
    $stmt->execute([
        'id' => $payer_member_id,
        'group_id' => $group['id'],
    ]);
    if (!$stmt->fetch()) {
        send_error('払った人がグループにいません。');
    }

    // 参加メンバーの負担率を取得
    $placeholders = implode(',', array_fill(0, count($participant_ids), '?'));
    $stmt = $pdo->prepare("SELECT id, default_ratio FROM members WHERE group_id = ? AND id IN ($placeholders)");
    $stmt->execute(array_merge([$group['id']], $participant_ids));
    $participants = $stmt->fetchAll();

    if (count($participants) !== count($participant_ids)) {
        send_error('選んだメンバーが見つかりませんでした。');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('INSERT INTO items (group_id, title, amount, payer_member_id, created_at) VALUES (:group_id, :title, :amount, :payer_member_id, NOW())');
        $stmt->execute([
            'group_id' => $group['id'],
            'title' => $title,
            'amount' => $amount,
            'payer_member_id' => $payer_member_id,
        ]);

        $item_id = (int)$pdo->lastInsertId();

        $stmtMember = $pdo->prepare('INSERT INTO item_members (item_id, member_id, ratio) VALUES (:item_id, :member_id, :ratio)');
        foreach ($participants as $participant) {
            $ratio = (float)$participant['default_ratio'];
            if ($ratio <= 0) {
                $ratio = 1.0;
            }
            $stmtMember->execute([
                'item_id' => $item_id,
                'member_id' => $participant['id'],
                'ratio' => $ratio,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        send_error('記録に失敗しました。もう一度試してね。', 500);
    }

    send_json([
        'message' => '支出を記録しました。',
        'item_id' => $item_id,
    ], 201);
}

function handle_list_items(): void
{
    $code = isset($_GET['code']) ? trim((string)$_GET['code']) : '';
    if ($code === '') {
        send_error('コードが必要です。');
    }

    $pdo = get_pdo();
    $group = find_group_by_code($pdo, $code);

    $stmt = $pdo->prepare('SELECT i.id, i.title, i.amount, i.created_at, i.payer_member_id, m.name AS payer_name FROM items i INNER JOIN members m ON i.payer_member_id = m.id WHERE i.group_id = :group_id ORDER BY i.created_at DESC');
    $stmt->execute(['group_id' => $group['id']]);
    $items = $stmt->fetchAll();

    $result = [];
    foreach ($items as $item) {
        $stmtParticipants = $pdo->prepare('SELECT im.member_id, im.ratio, mem.name FROM item_members im INNER JOIN members mem ON im.member_id = mem.id WHERE im.item_id = :item_id');
        $stmtParticipants->execute(['item_id' => $item['id']]);
        $participantRows = $stmtParticipants->fetchAll();

        $totalRatio = array_reduce($participantRows, function ($carry, $row) {
            return $carry + (float)$row['ratio'];
        }, 0.0);

        $participants = array_map(function ($row) use ($item, $totalRatio) {
            $ratio = (float)$row['ratio'];
            $share = $totalRatio > 0 ? ($item['amount'] * ($ratio / $totalRatio)) : 0;
            return [
                'member_id' => (int)$row['member_id'],
                'name' => $row['name'],
                'ratio' => $ratio,
                'share_amount' => round($share),
            ];
        }, $participantRows);

        $result[] = [
            'id' => (int)$item['id'],
            'title' => $item['title'],
            'amount' => (int)$item['amount'],
            'created_at' => $item['created_at'],
            'payer_member_id' => (int)$item['payer_member_id'],
            'payer_name' => $item['payer_name'],
            'participants' => $participants,
        ];
    }

    send_json(['items' => $result]);
}
