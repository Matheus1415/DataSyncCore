<?php

namespace App\Services\Sync;

use App\Contracts\Syncable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Support\Sync\SyncConfig;
use App\Models\SyncRecord;
use App\Models\SyncRecordItem;
use App\DTOs\SyncContext;

class SyncService
{
    protected SyncEngine $engine;
    private array $toIgnore;

    public function __construct(SyncEngine $engine)
    {
        $this->engine = $engine;
        $this->toIgnore = SyncConfig::ignoredFields();
    }

    public function sync(Syncable $entity, string $direction = 'local', ?SyncContext $context = null): array
    {
        if ($this->isSyncBlocked()) {
            return $this->handleBlockedSync();
        }

        $diffs = $this->engine->preview($entity, $context);
        $result = [
            'success' => 0,
            'errors'  => 0,
            'details' => []
        ];

        $batchId = now()->format('YmdHis');
        $user    = auth()->user();

        foreach ($diffs as $diff) {

            DB::beginTransaction();

            try {
                $status = $this->executeSync($entity, $diff, $direction);
                if ($this->isSuccessful($status)) {

                    $record = $this->persistRecord($entity, $diff, $status);

                    $this->persistItems(
                        record: $record,
                        entity: $entity,
                        diff: $diff,
                        status: $status,
                        direction: $direction,
                        userId: $user->id ?? null,
                        batchId: $batchId
                    );

                    DB::commit();

                    $this->registerSuccess($result, $diff, $status, $entity);

                } else {
                    DB::rollBack();
                    $this->registerError($result, $diff, $status);
                }

            } catch (\Exception $e) {
                DB::rollBack();
                $this->handleException($result, $diff, $e, $entity);
            }
        }

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | EXECUÇÃO DA SINCRONIZAÇÃO
    |--------------------------------------------------------------------------
    | Decide e executa a ação com base no tipo de diferença (diff).
    |
    | Regras:
    | - missing_local  → cria/atualiza no banco (toLocal)
    | - missing_remote → envia para API (toRemote)
    | - conflict       → depende da direção:
    |     'local'  → remoto vence (toLocal)
    |     'remote' → local vence (toRemote)
    |
    | @param Syncable $entity   Entidade sincronizável
    | @param array $diff        Estrutura gerada pelo DiffService
    | @param string $direction  'local' ou 'remote'
    |
    | @return SyncDTO|null
    |
    | Obs:
    | - Apenas executa a ação (não persiste nem loga)
    |--------------------------------------------------------------------------
    */
    protected function executeSync(Syncable $entity, array $diff, string $direction)
    {
        return match ($diff['type']) {
            'missing_local'  => $entity->toLocal($diff['data']['remote']),
            'missing_remote' => $entity->toRemote($diff['data']['local']),
            'conflict'       => $direction === 'local'
                ? $entity->toLocal($diff['data']['remote'])
                : $entity->toRemote($diff['data']['local']),
            default => null,
        };
    }

    protected function isSuccessful($status): bool
    {
        return $status && $status->success;
    }

    /*
    |--------------------------------------------------------------------------
    | PERSISTÊNCIA DO REGISTRO PRINCIPAL
    |--------------------------------------------------------------------------
    | Salva o estado FINAL sincronizado da entidade (snapshot consistente).
    |
    | IMPORTANTE:
    | - NÃO usa o diff (estado antigo)
    | - Usa o final_state retornado pelo SyncDTO (estado após sync)
    |
    | Isso garante:
    | ✔ hashes iguais após sincronização
    | ✔ evita reprocessamento desnecessário
    | ✔ consistência entre local e remoto
    |
    | @param Syncable $entity
    | @param array $diff
    | @param \App\DTOs\SyncDTO $status
    |
    | @return SyncRecord
    |--------------------------------------------------------------------------
    */
   protected function persistRecord(
        Syncable $entity,
        array $diff,
        \App\DTOs\SyncDTO $status
    ): ?SyncRecord {

        $final = $status->final_state ?? [];

        $local  = $final['local'] ?? [];
        $remote = $final['remote'] ?? [];

        if (!$this->isValidFinalState($local, $remote)) {
            Log::channel('sync')->warning('Tentativa de persistência inválida ignorada', [
                'entity' => $entity->getEntityName(),
                'diff'   => $diff,
                'final'  => $final,
            ]);

            return null;
        }

        return SyncRecord::updateOrCreate(
            [
                'entity'   => $entity->getEntityName(),
                'local_id' => $local['id'] ?? null,
            ],
            [
                'remote_id'      => $remote['external_id'] ?? null,
                'local_hash'     => $this->generateHash($local),
                'remote_hash'    => $this->generateHash($remote),
                'local_payload'  => $local,
                'remote_payload' => $remote,
                'status'         => SyncRecord::STATUS_SYNCED,
                'last_synced_at' => now(),
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PERSISTÊNCIA DO HISTÓRICO DETALHADO
    |--------------------------------------------------------------------------
    | Registra o histórico da sincronização (campo a campo ou geral).
    |
    | - Se houver mudanças → salva diff por campo
    | - Caso contrário → registra evento único (create/update)
    |
    | Inclui contexto:
    | - ação executada
    | - direção da sync
    | - usuário responsável
    | - batch da operação
    |
    | @param SyncRecord $record
    | @param Syncable $entity
    | @param array $diff
    | @param SyncDTO $status
    | @param string $f
    | @param int|null $userId
    | @param string $batchId
    |--------------------------------------------------------------------------
    */
    protected function persistItems(
        ?SyncRecord $record,
        Syncable $entity,
        array $diff,
        $status,
        string $direction,
        ?int $userId,
        string $batchId
    ): void {

        if (!$record) {
            return;
        }

        $changes = $diff['changes'] ?? [];

        if (!empty($changes)) {
            $this->logRecursiveChanges($record, $entity, $changes, $status, $direction, $userId, $batchId);
        } else {
            // Caso de fallback: Quando não há mudanças específicas detectadas, loga o estado geral
            $resolvedDirection = $this->resolveDirectionFromDiff($diff, $direction);
            $resolvedSource    = $this->resolveSourceFromDirection($resolvedDirection);

            SyncRecordItem::logChange([
                'sync_record_id' => $record->id,
                'entity'         => $entity->getEntityName(),
                'field'          => 'all',
                'new_value'      => $status->final_state['local'] 
                    ?? $status->final_state['remote'] 
                    ?? $diff['data']['local'] 
                    ?? $diff['data']['remote'],
                'action'    => $status->action,
                'source'    => $resolvedSource,
                'direction' => $resolvedDirection,
                'user_id'   => $userId,
                'batch_id'  => $batchId,
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Função auxiliar para tratar logs de campos complexos (arrays/objetos)
     */
    private function logRecursiveChanges($record, $entity, array $changes, $status, $direction, $userId, $batchId, $prefix = ''): void
    {
        foreach ($changes as $field => $values) {
            $fieldName = $prefix ? "{$prefix}.{$field}" : $field;

            // Se os valores forem arrays (mudança em um sub-objeto), entramos na recursão
            if (is_array($values['local'] ?? null) || is_array($values['remote'] ?? null)) {
                
                // Caso o Diff venha como um bloco completo de array
                $subChanges = $this->buildSubChanges($values['local'] ?? [], $values['remote'] ?? []);
                
                $this->logRecursiveChanges($record, $entity, $subChanges, $status, $direction, $userId, $batchId, $fieldName);
                continue;
            }

            SyncRecordItem::logChange([
                'sync_record_id' => $record->id,
                'entity'         => $entity->getEntityName(),
                'field'          => $fieldName,
                'old_value'      => $values['local'] ?? null,
                'new_value'      => $values['remote'] ?? null,
                'action'         => $status->action,
                'source'         => $this->resolveSource($direction),
                'direction'      => $this->resolveDirection($direction),
                'user_id'        => $userId,
                'batch_id'       => $batchId,
            ]);
        }
    }

    /**
     * Auxiliar para transformar dois sub-arrays em um formato de changes compatível
     */
    private function buildSubChanges(array $local, array $remote): array
    {
        $allKeys = array_unique(array_merge(array_keys($local), array_keys($remote)));
        $subChanges = [];

        foreach ($allKeys as $key) {
            if (($local[$key] ?? null) !== ($remote[$key] ?? null)) {
                $subChanges[$key] = [
                    'local' => $local[$key] ?? null,
                    'remote' => $remote[$key] ?? null
                ];
            }
        }
        return $subChanges;
    }

    protected function isValidFinalState(array $local, array $remote): bool
    {
        // Tem dados reais?
        $hasData = !empty($local) || !empty($remote);

        // Tem identidade?
        $hasIdentity =
            (!empty($local['id'])) ||
            (!empty($remote['external_id']));

        return $hasData && $hasIdentity;
    }

    protected function generateHash(array $data): string
    {
        $normalizeRecursive = function ($item) use (&$normalizeRecursive) {
            if (!is_array($item)) {
                if (is_null($item)) return '';
                if (is_bool($item)) return $item ? '1' : '0';
                return (string) $item;
            }

            // 1. Remove campos ignorados neste nível do array
            foreach ($this->toIgnore as $field) {
                if (array_key_exists($field, $item)) {
                    unset($item[$field]);
                }
            }

            // 2. Decide como ordenar:
            if (array_is_list($item)) {
                // Se for uma lista (ex: itens da venda), ordena os valores.
                // Isso evita que a ordem dos itens na resposta da API mude o hash.
                sort($item); 
            } else {
                // Se for um objeto/array associativo, ordena pelas chaves.
                ksort($item);
            }

            // 3. Aplica a normalização recursivamente
            return array_map($normalizeRecursive, $item);
        };

        $normalizedData = $normalizeRecursive($data);

        return md5(json_encode($normalizedData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function resolveSource(string $direction): string
    {
        return $direction === 'local' ? 'remote' : 'local';
    }

    protected function resolveDirection(string $direction): string
    {
        return $direction === 'local'
            ? 'remote_to_local'
            : 'local_to_remote';
    }

    protected function resolveDirectionFromDiff(array $diff, string $direction): string
    {
        return match ($diff['type']) {
            'missing_local'  => 'remote_to_local',
            'missing_remote' => 'local_to_remote',
            'conflict'       => $direction === 'local'
                ? 'remote_to_local'
                : 'local_to_remote',
            default          => 'unknown',
        };
    }

    protected function resolveSourceFromDirection(string $direction): string
    {
        return match ($direction) {
            'remote_to_local' => 'remote',
            'local_to_remote' => 'local',
            default           => 'system',
        };
    }

    protected function registerSuccess(array &$result, array $diff, $status, Syncable $entity): void
    {
        $result['success']++;

        $result['details'][] = [
            'key'    => $diff['key'],
            'status' => 'success',
            'action' => $status->action
        ];

        Log::channel('sync')->info("Sync sucesso [{$entity->getEntityName()}]", [
            'key' => $diff['key'],
            'action' => $status->action
        ]);
    }

    protected function registerError(array &$result, array $diff, $status): void
    {
        $result['errors']++;

        $result['details'][] = [
            'key'     => $diff['key'],
            'status'  => 'error',
            'message' => $status->message ?? 'Falha'
        ];
    }

    protected function handleException(array &$result, array $diff, \Exception $e, Syncable $entity): void
    {
        $result['errors']++;

        $result['details'][] = [
            'key'     => $diff['key'] ?? 'N/A',
            'status'  => 'critical_error',
            'message' => $e->getMessage()
        ];

        Log::channel('sync')->error("Erro crítico [{$entity->getEntityName()}]", [
            'key'   => $diff['key'] ?? 'N/A',
            'error' => $e->getMessage()
        ]);
    }

    protected function handleBlockedSync(): array
    {
        Log::channel('sync')->warning("Sync bloqueado", [
            'env' => app()->environment()
        ]);

        return [
            'status'  => 'blocked',
            'message' => 'Sincronização bloqueada por segurança.'
        ];
    }

    protected function isSyncBlocked(): bool
    {
        $currentEnv = app()->environment();

        $blockedEnvs = array_map(
            fn ($env) => strtolower(trim($env)),
            config('sync.blocked_environments', [])
        );

        return in_array($currentEnv, $blockedEnvs, true);
    }
}