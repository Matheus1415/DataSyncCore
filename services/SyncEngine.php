<?php

namespace App\Services\Sync;

use App\Contracts\Syncable;
use App\Services\Sync\DiffService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\DTOs\SyncContext;

class SyncEngine
{
    /*
    |----------------------------------------------------------------------
    | Motor de Processamento de Sincronização (Orquestrador)
    |----------------------------------------------------------------------
    | Responsável por coordenar o fluxo entre dados locais e remotos, 
    | transformando-os através de DTOs e identificando as discrepâncias
    | através do serviço de diferenciação (DiffService).
    |
    | Este motor garante que os dados de diferentes fontes sejam 
    | normalizados antes da comparação de estado.
    | ----------------------------------------------------------------------
    */
    protected DiffService $diffService;

    public function __construct(DiffService $diffService)
    {
        $this->diffService = $diffService;
    }

    /**
     * Executa o preview de sincronização.
     * * @param Syncable $entity
     * @return array
     */
    public function preview(Syncable $entity, ?SyncContext $context = null): array
    {
        // 1. Busca os dados brutos de ambas as fontes
        $localData = $entity->getLocalData($context);
        $remoteData = $entity->getRemoteData($context);
        
        // 2. Transforma coleções brutas em arrays padronizados via DTO
        $localDTOs = $this->mapCollection($localData, [$entity, 'mapFromLocal'], 'Local');
        $remoteDTOs = $this->mapCollection($remoteData, [$entity, 'mapFromRemote'], 'Remote');
        
        // 3. Indexa os arrays pela chave única configurada na entidade
        $uniqueKey = $entity->getUniqueKey();
        
        $localIndexed  = $this->indexByKey($localDTOs, $uniqueKey);
        $remoteIndexed = $this->indexByKey($remoteDTOs, $uniqueKey);

        // 4. Retorna o mapeamento de diferenças
        $merged = $this->mergeWithMeta($localIndexed, $remoteIndexed, $entity);

        return $this->diffService->compare($merged);
    }

    /**
     * Aplica o mapeamento em uma coleção com proteção contra falhas.
     *
     * @param array $data
     * @param callable $mapper
     * @param string $context
     * @return array
     */
    protected function mapCollection(array $data, callable $mapper, string $context): array
    {
        $results = [];

        foreach ($data as $item) {
            try {
                $mapped = $mapper($item);
                
                if (!empty($mapped)) {
                    $results[] = $mapped;
                }
            } catch (\Exception $e) {
                Log::channel('sync')->error("Erro de mapeamento no SyncEngine [{$context}]: " . $e->getMessage(), $this->actorContext([
                    'payload' => $item,
                ]));
                continue;
            }
        }

        return $results;
    }

    /**
     * Indexa um array usando uma chave específica como índice do mapa.
     *
     * @param array $data
     * @param string $key
     * @return array
     */
    protected function indexByKey(array $data, string $key): array
    {
        $indexed = [];
        $counter = 0;

        foreach ($data as $item) {
            $itemArray = is_object($item) ? (array) $item : $item;

            // Pegamos o valor da chave
            $keyValue = $itemArray[$key] ?? null;

            // Se a chave estiver vazia, geramos uma chave temporária única
            if (empty($keyValue)) {
                $counter++;
                $keyValue = "undefined_key_{$counter}";
                Log::channel('sync')->warning('Registro com chave única vazia durante indexação.', $this->actorContext([
                    'key' => $key,
                    'generated_key' => $keyValue,
                    'payload' => $itemArray,
                ]));
            }

            // Se houver duplicidade de código no banco, o PHP vai sobrescrever.
            $indexed[$keyValue] = $itemArray;
        }

        return $indexed;
    }

    protected function mergeWithMeta(array $local, array $remote, Syncable $entity): array
    {
        $merged = [];

        $allKeys = array_unique(array_merge(
            array_keys($local),
            array_keys($remote)
        ));

        foreach ($allKeys as $key) {
            $localItem  = $local[$key] ?? null;
            $remoteItem = $remote[$key] ?? null;

            $merged[$key] = [
                'key' => $key,

                'meta' => [
                    'local_id' => $localItem
                        ? $entity->extractLocalId($localItem)
                        : null,

                    'external_id' => $remoteItem
                        ? $entity->extractRemoteId($remoteItem)
                        : null,
                ],

                'local'  => $localItem,
                'remote' => $remoteItem,
            ];
        }

        return $merged;
    }

    protected function actorContext(array $extra = []): array
    {
        $user = Auth::user();

        $context = [
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? $user?->email ?? 'system',
        ];

        return array_merge($context, $extra);
    }
}
