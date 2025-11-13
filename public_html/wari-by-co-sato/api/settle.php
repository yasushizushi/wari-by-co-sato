<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'calculate':
        handle_calculate();
        break;
    default:
        send_error('この操作は対応していません。', 404);
}

function handle_calculate(): void
{
    $code = isset($_GET['code']) ? trim((string)$_GET['code']) : '';
    if ($code === '') {
        send_error('コードが必要です。');
    }

    $pdo = get_pdo();
    $group = find_group_by_code($pdo, $code);
    $members = fetch_members($pdo, (int)$group['id']);

    if (empty($members)) {
        send_error('まずはメンバーを追加してね。');
    }

    $memberMap = [];
    foreach ($members as $member) {
        $memberMap[$member['id']] = [
            'id' => (int)$member['id'],
            'name' => $member['name'],
            'balance' => 0.0,
        ];
    }

    $stmtItems = $pdo->prepare('SELECT i.id, i.amount, i.payer_member_id FROM items i WHERE i.group_id = :group_id');
    $stmtItems->execute(['group_id' => $group['id']]);
    $items = $stmtItems->fetchAll();

    if (empty($items)) {
        send_json([
            'group' => ['name' => $group['name'], 'code' => $group['code']],
            'balances' => array_map(function ($member) {
                return [
                    'id' => $member['id'],
                    'name' => $member['name'],
                    'balance' => 0,
                ];
            }, $memberMap),
            'settlements' => [],
            'total_spent' => 0,
            'message' => 'まだ支出が登録されていません。'
        ]);
    }

    $stmtParticipants = $pdo->prepare('SELECT im.member_id, im.ratio FROM item_members im WHERE im.item_id = :item_id');
    $totalSpent = 0;

    foreach ($items as $item) {
        $itemId = (int)$item['id'];
        $amount = (float)$item['amount'];
        $payerId = (int)$item['payer_member_id'];
        $totalSpent += $amount;

        if (!isset($memberMap[$payerId])) {
            continue;
        }

        $stmtParticipants->execute(['item_id' => $itemId]);
        $participants = $stmtParticipants->fetchAll();
        if (empty($participants)) {
            $memberMap[$payerId]['balance'] += $amount;
            continue;
        }

        $totalRatio = array_reduce($participants, function ($carry, $row) {
            return $carry + max(0.0, (float)$row['ratio']);
        }, 0.0);

        if ($totalRatio <= 0) {
            $memberMap[$payerId]['balance'] += $amount;
            continue;
        }

        foreach ($participants as $participant) {
            $participantId = (int)$participant['member_id'];
            if (!isset($memberMap[$participantId])) {
                continue;
            }
            $ratio = max(0.0, (float)$participant['ratio']);
            $share = $amount * ($ratio / $totalRatio);
            $memberMap[$participantId]['balance'] -= $share;
        }

        $memberMap[$payerId]['balance'] += $amount;
    }

    // 丸め処理（円単位）
    $roundedBalances = [];
    $sumBalances = 0;
    foreach ($memberMap as $member) {
        $rounded = round($member['balance']);
        $roundedBalances[$member['id']] = $rounded;
        $sumBalances += $rounded;
    }

    if ($sumBalances !== 0) {
        foreach ($roundedBalances as $memberId => $value) {
            if ($value > 0) {
                $roundedBalances[$memberId] -= $sumBalances;
                break;
            }
        }
    }

    $balancesOutput = [];
    $payers = [];
    $receivers = [];

    foreach ($memberMap as $memberId => $member) {
        $rounded = $roundedBalances[$memberId] ?? 0;
        $balancesOutput[] = [
            'id' => $member['id'],
            'name' => $member['name'],
            'balance' => $rounded,
        ];

        if ($rounded < 0) {
            $payers[] = ['id' => $member['id'], 'name' => $member['name'], 'amount' => abs($rounded)];
        } elseif ($rounded > 0) {
            $receivers[] = ['id' => $member['id'], 'name' => $member['name'], 'amount' => $rounded];
        }
    }

    $settlements = [];
    $payerIndex = 0;
    $receiverIndex = 0;

    while ($payerIndex < count($payers) && $receiverIndex < count($receivers)) {
        $payer = &$payers[$payerIndex];
        $receiver = &$receivers[$receiverIndex];

        $amount = min($payer['amount'], $receiver['amount']);
        if ($amount <= 0) {
            if ($payer['amount'] <= 0) {
                $payerIndex++;
            }
            if ($receiver['amount'] <= 0) {
                $receiverIndex++;
            }
            continue;
        }

        $settlements[] = [
            'from' => $payer['name'],
            'to' => $receiver['name'],
            'amount' => $amount,
        ];

        $payer['amount'] -= $amount;
        $receiver['amount'] -= $amount;

        if ($payer['amount'] <= 0.5) {
            $payerIndex++;
        }
        if ($receiver['amount'] <= 0.5) {
            $receiverIndex++;
        }
    }

    send_json([
        'group' => [
            'name' => $group['name'],
            'code' => $group['code'],
        ],
        'balances' => $balancesOutput,
        'settlements' => $settlements,
        'total_spent' => round($totalSpent),
        'message' => 'みんなのバランスを計算しました。仲良く分け合いましょう。'
    ]);
}
