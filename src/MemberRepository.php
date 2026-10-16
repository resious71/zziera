<?php
/**
 * 메일링 멤버 관리 (PRD 4장 H-3) — 모든 작업은 관리자만 한다.
 *
 * 상태: active(수신) / suspended(정지: 일시 중단) / unsubscribed(관리자가 구독 해지 처리)
 * 발송 대상은 active 만이다(activeEmails()).
 * 감사 로그에는 이메일을 마스킹해서 남긴다.
 */
final class MemberRepository
{
    public const STATUSES = ['active', 'suspended', 'unsubscribed'];
    public const STATUS_LABELS = ['active' => '수신', 'suspended' => '정지', 'unsubscribed' => '구독 해지'];
    /** 한 번에 붙여 넣을 수 있는 최대 주소 수 */
    public const BULK_MAX = 1000;

    /**
     * 여러 주소 한꺼번에 추가(줄바꿈·쉼표·세미콜론·공백 구분). 즉시 active.
     * @return array{added:list<string>, duplicate:list<string>, invalid:list<string>}
     */
    public static function addBulk(string $raw): array
    {
        $tokens = preg_split('/[\s,;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) > self::BULK_MAX) {
            throw new ValidationException('한 번에 ' . number_format(self::BULK_MAX) . '개까지 추가할 수 있습니다.');
        }
        $result = ['added' => [], 'duplicate' => [], 'invalid' => []];
        $seen = [];
        $now = Time::now();

        Db::transaction(static function () use ($tokens, &$result, &$seen, $now): void {
            foreach ($tokens as $token) {
                // "홍길동 <a@b.com>" 형태에서 주소만 꺼낸다
                $token = trim($token, " \t<>\"'");
                $email = Input::email($token);
                if ($email === null) {
                    $result['invalid'][] = mb_substr($token, 0, 100);
                    continue;
                }
                if (isset($seen[$email]) || Db::value('SELECT 1 FROM members WHERE email = ?', [$email]) !== null) {
                    $result['duplicate'][] = $email;
                    $seen[$email] = true;
                    continue;
                }
                Db::exec('INSERT INTO members (email, status, created_at) VALUES (?, ?, ?)', [$email, 'active', $now]);
                $seen[$email] = true;
                $result['added'][] = $email;
            }
        });

        if ($result['added'] !== []) {
            $sample = implode(', ', array_map([Audit::class, 'maskEmail'], array_slice($result['added'], 0, 5)));
            Audit::log('member_add', 'member', null, count($result['added']) . '명 추가: ' . $sample . (count($result['added']) > 5 ? ' 외' : ''));
        }
        return $result;
    }

    /**
     * 목록(상태 필터·검색·페이지)
     * @return array{items:list<array>, total:int, page:int, pages:int, counts:array<string,int>}
     */
    public static function list(?string $status, string $q, int $page, int $perPage = 20): array
    {
        $where = [];
        $params = [];
        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $where[] = "email LIKE ? ESCAPE '\\'";
            $params[] = '%' . strtr(mb_strtolower($q), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $total = (int) Db::value("SELECT COUNT(*) FROM members {$whereSql}", $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $items = Db::all(
            "SELECT id, email, status, created_at FROM members {$whereSql} ORDER BY id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, ($page - 1) * $perPage])
        );

        $counts = array_fill_keys(self::STATUSES, 0);
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM members GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        return [
            'items'  => array_map(static fn (array $r) => ['id' => (int) $r['id']] + $r, $items),
            'total'  => $total,
            'page'   => $page,
            'pages'  => $pages,
            'counts' => $counts,
        ];
    }

    public static function find(int $id): ?array
    {
        return Db::row('SELECT id, email, status, created_at FROM members WHERE id = ?', [$id]);
    }

    /** 이메일 주소 수정(오타 수정용) */
    public static function updateEmail(int $id, string $raw): void
    {
        $member = self::mustFind($id);
        $email = Input::email($raw);
        if ($email === null) {
            throw new ValidationException('이메일 형식이 올바르지 않습니다.');
        }
        if ($email === $member['email']) {
            return;
        }
        if (Db::value('SELECT 1 FROM members WHERE email = ? AND id <> ?', [$email, $id]) !== null) {
            throw new ValidationException('이미 등록된 주소입니다.');
        }
        Db::exec('UPDATE members SET email = ? WHERE id = ?', [$email, $id]);
        Audit::log('member_update', 'member', $id, Audit::maskEmail($member['email']) . ' → ' . Audit::maskEmail($email));
    }

    /**
     * 상태 변경. 허용되는 전환만 받는다.
     *  suspend: active → suspended / resume: suspended → active
     *  unsubscribe: active·suspended → unsubscribed / reactivate: unsubscribed → active
     */
    public static function changeStatus(int $id, string $action): array
    {
        $rules = [
            'suspend'     => [['active'], 'suspended', 'member_suspend', '정지'],
            'resume'      => [['suspended'], 'active', 'member_resume', '재개'],
            'unsubscribe' => [['active', 'suspended'], 'unsubscribed', 'member_unsubscribe', '구독 해지 처리'],
            'reactivate'  => [['unsubscribed'], 'active', 'member_reactivate', '재활성화'],
        ];
        if (!isset($rules[$action])) {
            throw new ValidationException('알 수 없는 작업입니다.');
        }
        [$from, $to, $event, $label] = $rules[$action];
        $member = self::mustFind($id);
        if (!in_array($member['status'], $from, true)) {
            throw new ValidationException("현재 상태(" . self::STATUS_LABELS[$member['status']] . ")에서는 {$label}할 수 없습니다.");
        }
        Db::exec('UPDATE members SET status = ? WHERE id = ?', [$to, $id]);
        Audit::log($event, 'member', $id, Audit::maskEmail($member['email']) . ': ' . $member['status'] . ' → ' . $to);
        return ['status' => $to];
    }

    public static function delete(int $id): void
    {
        $member = self::mustFind($id);
        Db::exec('DELETE FROM members WHERE id = ?', [$id]);
        Audit::log('member_delete', 'member', $id, Audit::maskEmail($member['email']) . ' 삭제(상태: ' . $member['status'] . ')');
    }

    /** 발송 대상(active) 주소 목록 — 5단계 발송에서 사용 */
    public static function activeEmails(): array
    {
        return array_column(Db::all("SELECT email FROM members WHERE status = 'active' ORDER BY id"), 'email');
    }

    private static function mustFind(int $id): array
    {
        $member = self::find($id);
        if ($member === null) {
            throw new ValidationException('멤버를 찾을 수 없습니다. 이미 삭제되었을 수 있습니다.');
        }
        return $member;
    }
}
