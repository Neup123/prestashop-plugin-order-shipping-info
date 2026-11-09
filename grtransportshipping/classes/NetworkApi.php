<?php
require_once __DIR__ . '/NetworkRepository.php';

/** Token-scoped network data API: no customer session, no anonymous writes. */
final class GrTransportNetworkApi
{
    private const KEYS=['read'=>'GRTRANS_NETWORK_READ_HASH','write'=>'GRTRANS_NETWORK_WRITE_HASH'];

    /** Returns the plaintext exactly once; only its SHA-256 hash is persisted. */
    public static function rotate(string $scope): string
    {
        if (!isset(self::KEYS[$scope])) throw new InvalidArgumentException('Invalid API token scope');
        $token=bin2hex(random_bytes(32));
        if (!Configuration::updateValue(self::KEYS[$scope],hash('sha256',$token))) {
            throw new RuntimeException('Cannot rotate network API key');
        }
        return $token;
    }

    public static function authenticate(string $scope,string $token): void
    {
        if ($token==='' || strlen($token)>256) throw new DomainException('API authentication required',403);
        $candidate=hash('sha256',$token);
        $scopes=$scope==='read' ? ['read','write'] : ['write'];
        foreach ($scopes as $allowed) {
            $stored=(string)Configuration::get(self::KEYS[$allowed]);
            if ($stored!=='' && hash_equals($stored,$candidate)) return;
        }
        throw new DomainException('API token is not authorized for this operation',403);
    }

    public static function handle(string $method,array $query,array $payload,string $token): array
    {
        $method=strtoupper($method);
        $write=in_array($method,['POST','PUT','PATCH','DELETE'],true);
        self::authenticate($write ? 'write' : 'read',$token);
        $entity=$query['entity'] ?? null;
        $id=isset($query['id']) && is_scalar($query['id']) && ctype_digit((string)$query['id'])
            ? (int)$query['id'] : 0;
        if ($method==='GET') {
            if ($entity===null) return GrTransportNetworkRepository::snapshot();
            $entity=self::entity($entity);
            if ($id) {
                $record=GrTransportNetworkRepository::find($entity,$id);
                if (!$record) throw new InvalidArgumentException('Network record not found');
                return $record;
            }
            return ['items'=>GrTransportNetworkRepository::all($entity)];
        }
        if ($method==='POST' && ($query['bulk'] ?? null)==='1') {
            return ['created'=>GrTransportNetworkRepository::bulk($payload)];
        }
        $entity=self::entity($entity);
        if ($method==='POST' && ($query['confirm'] ?? null)==='1' && $id>0) {
            GrTransportNetworkRepository::confirm($entity,$id);
            return ['id'=>$id,'record'=>GrTransportNetworkRepository::find($entity,$id)];
        }
        if ($method==='POST' && !$id) {
            $id=GrTransportNetworkRepository::save($entity,$payload);
            return ['id'=>$id,'record'=>GrTransportNetworkRepository::find($entity,$id)];
        }
        if (in_array($method,['PATCH','PUT'],true) && $id>0) {
            GrTransportNetworkRepository::save($entity,$payload,$id);
            return ['id'=>$id,'record'=>GrTransportNetworkRepository::find($entity,$id)];
        }
        if ($method==='DELETE' && $id>0) {
            GrTransportNetworkRepository::delete($entity,$id);
            return ['deleted'=>$id];
        }
        throw new InvalidArgumentException('Unsupported API operation');
    }

    public static function parseCsv(string $entity,string $body): array
    {
        $entity=self::entity($entity);
        if (strlen($body)>500000) throw new InvalidArgumentException('CSV exceeds 500 KB');
        $stream=fopen('php://temp','w+');
        if (!$stream) throw new RuntimeException('Cannot read CSV');
        fwrite($stream,$body);
        rewind($stream);
        try {
            $header=fgetcsv($stream);
            if (!is_array($header)) throw new InvalidArgumentException('CSV needs a header row');
            $allowed=array_keys(GrTransportNetworkFields::fields($entity));
            if ($header!==array_values(array_unique($header))
                || array_diff($header,$allowed)) {
                throw new InvalidArgumentException('CSV contains duplicate or unknown field names');
            }
            $rows=[];
            while (($fields=fgetcsv($stream))!==false) {
                if ($fields===[null]) continue;
                if (count($fields)!==count($header)) throw new InvalidArgumentException('CSV column count mismatch');
                $rows[]=array_combine($header,$fields);
                if (count($rows)>1000) throw new InvalidArgumentException('CSV batch too large');
            }
            if (!$rows) throw new InvalidArgumentException('CSV contains no records');
            return [$entity=>$rows];
        } finally {
            fclose($stream);
        }
    }

    private static function entity($entity): string
    {
        if (!is_string($entity) || !in_array($entity,GrTransportNetworkFields::ENTITIES,true)) {
            throw new InvalidArgumentException('Unknown API entity');
        }
        return $entity;
    }
}
