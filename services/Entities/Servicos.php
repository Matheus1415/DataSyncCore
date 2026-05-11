<?php

namespace App\Services\Sync\Entities;

use App\Contracts\Syncable;
use App\Models\Servicos;
use App\Services\RemoteHTTPServicos;
use App\DTOs\ServiceDTO;
use App\DTOs\SyncDTO;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\DTOs\SyncContext;


class Servicos implements Syncable
{
    protected $remoteServicos;

    public function __construct(RemoteHTTPServicos $remoteServicos)
    {
        $this->remoteServicos = $remoteServicos;
    }

    public function getEntityName(): string
    {
        return 'servicos';
    }

    public function getUniqueKey(): string
    {
        return 'codigo';
    }

    public function getLocalKey(): string
    {
        return 'external_id';
    }

    public function getRemoteKey(): string
    {
        return 'service_uuid';
    }

    public function extractLocalId(array $data): mixed
    {
        return $data['id'] ?? null;
    }

    public function extractRemoteId(array $data): mixed
    {
        return $data['external_id']
            ?? $data['id']
            ?? null;
    }

    public function getLocalData(?SyncContext $context = null): array
    {
        return Servicos::all()->toArray();
    }

    public function getRemoteData(?SyncContext $context = null): array
    {
        try {
            $remoteData = $this->remoteServicos->getServices([
                'tamanho_pagina' => 50,
            ]);

            if (isset($remoteData['error']) || !isset($remoteData['data']['itens'])) {
                Log::channel('sync')->warning('RemoteHTTPServicos API retornou erro ou estrutura vazia.', $this->actorContext([
                    'response' => $remoteData,
                ]));
                return [];
            }

            return $remoteData['data']['itens'];

        } catch (\Exception $e) {
            Log::channel('sync')->error('Erro na comunicação com RemoteHTTPServicos: ' . $e->getMessage(), $this->actorContext());
            return [];
        }
    }

    public function mapFromRemote(array $remote): array
    {
        if (!$remote) {
            return [];
        }

        return ServiceDTO::fromApi($remote)->toArray();
    }

    public function mapFromLocal(array $local): array
    {
        if (!$local) {
            return [];
        }

        return ServiceDTO::fromDatabase($local)->toArray();
    }

    public function toLocal(array $dtoRemote): SyncDTO
    {
        try {
            $dto = ServiceDTO::fromArray($dtoRemote);

            if (empty($dto->external_id)) {
                throw new \Exception('External ID não informado para sincronização.');
            }

            $model = Servicos::updateOrCreate(
                [
                    'service_uuid' => $dto->external_id
                ],
                [
                    'nome'   => $dto->nome,
                    'codigo' => $dto->codigo,
                    'valor'  => $dto->valor,
                    'status' => $dto->status,
                ]
            );

            $action = $model->wasRecentlyCreated ? 'create' : 'update';

            $finalState = [
                'local' => [
                    'external_id' => $dto->external_id,
                    'id'          => $model->id,
                    'nome'        => $dto->nome,
                    'codigo'      => $dto->codigo,
                    'valor'       => $dto->valor,
                    'status'      => $dto->status,
                    'updated_at'  => $model->updated_at?->toISOString(),
                ],
                'remote' => [
                    'external_id' => $dto->external_id,
                    'id'          => null,
                    'nome'        => $dto->nome,
                    'codigo'      => $dto->codigo,
                    'valor'       => $dto->valor,
                    'status'      => $dto->status,
                    'updated_at'  => null, 
                ]
            ];

            Log::channel('sync')->info(
                'Serviço sincronizado para o banco.',
                $this->actorContext([
                    'action'       => $action,
                    'external_id'  => $dto->external_id,
                    'local_id'     => $model->id,
                    'codigo'       => $dto->codigo,
                ])
            );

            return SyncDTO::success(
                message: "Serviço [{$dto->codigo}] sincronizado com sucesso.",
                data: $model,
                action: $action,
                final_state: $finalState
            );

        } catch (\Throwable $e) {
            Log::channel('sync')->error(
                'Falha ao persistir serviço localmente: ' . $e->getMessage(),
                $this->actorContext([
                    'external_id' => $dtoRemote['external_id'] ?? null,
                    'codigo'      => $dtoRemote['codigo'] ?? null,
                ])
            );

            return SyncDTO::error(
                message: "Falha ao persistir serviço localmente: " . $e->getMessage(),
                errors: [
                    'external_id' => $dtoRemote['external_id'] ?? 'N/A'
                ]
            );
        }
    }

    public function toRemote(array $dtoData): SyncDTO
    {
        try {
            $dto = ServiceDTO::fromArray($dtoData);

            $servicoLocal = Servicos::find($dto->id);

            if (!$servicoLocal) {
                Log::channel('sync')->warning(
                    'Serviço local não encontrado para sincronização.',
                    $this->actorContext([
                        'local_id' => $dto->id,
                        'codigo'   => $dto->codigo,
                    ])
                );

                return SyncDTO::error("Serviço local não encontrado para sincronização.");
            }

            $externalId = $servicoLocal->service_uuid 
                ?? $dto->external_id 
                ?? null;

            Log::channel('sync')->info('Resolvendo ID remoto', [
                'local_id'      => $servicoLocal->id,
                'service_uuid'  => $servicoLocal->service_uuid,
                'dto_external'  => $dto->external_id,
                'resolved_id'   => $externalId,
            ]);

            /*
            |--------------------------------------------------------------------------
            | PAYLOAD
            |--------------------------------------------------------------------------
            */
            
            $dataForCA = [
                'descricao'    => $dto->nome,
                'codigo'       => $dto->codigo,
                'preco'        => (float) $dto->valor,
                'tipo_servico' => 'PRESTADO',
                'status'       => 'ATIVO',
            ];

            Log::channel('sync')->info('Payload enviado para Conta Azul', $dataForCA);

            /*
            |--------------------------------------------------------------------------
            | ENVIO PARA API
            |--------------------------------------------------------------------------
            */
            if (empty($externalId)) {

                // CREATE
                $response = $this->remoteServicos->submitService($dataForCA);

                Log::channel('sync')->info('Resposta CREATE CA', $response);

                if (!empty($response['data']['id'])) {
                    $servicoLocal->update([
                        'service_uuid' => $response['data']['id'],
                    ]);

                    $servicoLocal->refresh();
                }

                $action = 'create';

            } else {

                // UPDATE
                $response = $this->remoteServicos->updateService(
                    $externalId,
                    $dataForCA
                );

                Log::channel('sync')->info('Resposta UPDATE CA', $response);

                $action = 'update';
            }

            /*
            |--------------------------------------------------------------------------
            | LOG
            |--------------------------------------------------------------------------
            */
            Log::channel('sync')->info(
                'Serviço enviado para Conta Azul.',
                $this->actorContext([
                    'action'      => $action,
                    'local_id'    => $servicoLocal->id,
                    'external_id' => $servicoLocal->service_uuid,
                    'codigo'      => $dto->codigo,
                ])
            );

            /*
            |--------------------------------------------------------------------------
            | ESTADO FINAL (PONTO CRÍTICO)
            |--------------------------------------------------------------------------
            */
            $externalId = $servicoLocal->service_uuid 
                ?? ($response['data']['id'] ?? null);

            $finalState = [
                'local' => [
                    'id'          => $servicoLocal->id,
                    'external_id' => $externalId,
                    'nome'        => $servicoLocal->nome,
                    'codigo'      => $servicoLocal->codigo,
                    'valor'       => $servicoLocal->valor,
                    'status'      => $servicoLocal->status,
                    'updated_at'  => optional($servicoLocal->updated_at)?->toISOString(),
                ],
                'remote' => [
                    'id'          => null,
                    'external_id' => $externalId,
                    'nome'        => $dto->nome,
                    'codigo'      => $dto->codigo,
                    'valor'       => $dto->valor,
                    'status'      => $dto->status,
                    'updated_at'  => null,
                ]
            ];

            /*
            |--------------------------------------------------------------------------
            | RETORNO PADRONIZADO
            |--------------------------------------------------------------------------
            */
            return SyncDTO::success(
                message: "Serviço [{$dto->codigo}] sincronizado com sucesso.",
                data: $response,
                action: $action,
                final_state: $finalState
            );

        } catch (\Throwable $e) {

            Log::channel('sync')->error(
                "Erro ao enviar serviço para Conta Azul: " . $e->getMessage(),
                $this->actorContext([
                    'local_id'    => $dtoData['id'] ?? null,
                    'external_id' => $dtoData['external_id'] ?? null,
                ])
            );

            return SyncDTO::error(
                message: "Falha na comunicação com a API: " . $e->getMessage(),
                errors: ['exception' => get_class($e)]
            );
        }
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










