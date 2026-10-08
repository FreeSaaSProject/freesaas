<?php
declare(strict_types=1);

/** Shared view helpers for wellness forms. */
final class WellnessUi
{
    public static function datetimeLocal(?int $ts): string
    {
        $ts = $ts ?? time();
        if ($ts < 1) {
            $ts = time();
        }
        return date('Y-m-d\TH:i', $ts);
    }

    public static function dateOnly(?int $ts): string
    {
        $ts = $ts ?? time();
        if ($ts < 1) {
            $ts = time();
        }
        return date('Y-m-d', $ts);
    }

    public static function formatDt(?int $ts): string
    {
        $ts = (int)$ts;
        if ($ts < 1) {
            return '—';
        }
        return date('d.m.Y H:i', $ts);
    }

    public static function formatDate(?int $ts): string
    {
        $ts = (int)$ts;
        if ($ts < 1) {
            return '—';
        }
        return date('d.m.Y', $ts);
    }

    /**
     * Standard CRUD edit POST + AgentHttp flow.
     *
     * @param callable():?array $findOwned
     * @param callable(array):array $dataFromPost
     * @param callable(array):int $create
     * @param callable(int,array):void $update
     * @return array{isNew:bool,id:int,row:array<string,mixed>,message:string,error:string,done:bool}
     */
    public static function handleEdit(
        callable $findOwned,
        callable $dataFromPost,
        callable $create,
        callable $update,
        string $listPath,
        bool $wantsJson
    ): array {
        $id = (int)(Router::param('id') ?? 0);
        $isNew = $id < 1;
        $row = [];
        if (!$isNew) {
            $found = $findOwned($id);
            if (!$found) {
                if ($wantsJson) {
                    AgentHttp::error(t('wellness.err.not_found'), 404);
                    return ['isNew' => false, 'id' => $id, 'row' => [], 'message' => '', 'error' => '', 'done' => true];
                }
                http_response_code(404);
                View::render('error', [
                    'title' => t('core.common.not_found'),
                    'message' => t('wellness.err.not_found'),
                ]);
                return ['isNew' => false, 'id' => $id, 'row' => [], 'message' => '', 'error' => '', 'done' => true];
            }
            $row = $found;
        }

        $message = '';
        $error = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                $data = $dataFromPost($_POST);
                if ($isNew) {
                    $newId = $create($data);
                    if ($wantsJson) {
                        AgentHttp::emit(['ok' => true, 'id' => $newId, 'saved' => true]);
                        return ['isNew' => true, 'id' => $newId, 'row' => $row, 'message' => '', 'error' => '', 'done' => true];
                    }
                    redirect($listPath . '/' . $newId . '/edit?saved=1');
                    return ['isNew' => true, 'id' => $newId, 'row' => $row, 'message' => '', 'error' => '', 'done' => true];
                }
                $update($id, $data);
                $row = $findOwned($id) ?: $row;
                if ($wantsJson) {
                    AgentHttp::emit(['ok' => true, 'id' => $id, 'saved' => true]);
                    return ['isNew' => false, 'id' => $id, 'row' => $row, 'message' => '', 'error' => '', 'done' => true];
                }
                $message = t('core.common.saved');
            } catch (Throwable $e) {
                $code = $e instanceof RuntimeException && $e->getMessage() === t('wellness.err.forbidden') ? 403 : 400;
                if ($wantsJson) {
                    AgentHttp::error($e->getMessage(), $code);
                    return ['isNew' => $isNew, 'id' => $id, 'row' => $row, 'message' => '', 'error' => '', 'done' => true];
                }
                $error = $e->getMessage();
                $row = array_merge($row, $_POST);
            }
        }

        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'item' => $row, 'is_new' => $isNew]);
            return ['isNew' => $isNew, 'id' => $id, 'row' => $row, 'message' => '', 'error' => '', 'done' => true];
        }

        if (isset($_GET['saved']) && $message === '') {
            $message = t('core.common.saved');
        }

        return [
            'isNew' => $isNew,
            'id' => $id,
            'row' => $row,
            'message' => $message,
            'error' => $error,
            'done' => false,
        ];
    }
}
