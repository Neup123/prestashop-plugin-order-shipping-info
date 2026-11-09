<?php
if (!defined('_PS_VERSION_')) { exit; }
require_once __DIR__ . '/../../classes/NetworkApi.php';

/** Documented machine endpoint: index.php?fc=module&module=grtransportshipping&controller=network */
class GrtransportshippingNetworkModuleFrontController extends ModuleFrontController
{
    public $auth=false;
    public $ssl=true;

    public function initContent()
    {
        // Never render a PrestaShop page or include tokens in the URL.
        $status=200;
        $response=[];
        try {
            $authorization=(string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
            $token=preg_match('/^Bearer ([a-f0-9]{64})$/D',$authorization,$match) ? $match[1] : '';
            $method=strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            $query=[];
            foreach (['entity','id','bulk','format','confirm'] as $field) {
                if (isset($_GET[$field]) && is_scalar($_GET[$field])) $query[$field]=(string)$_GET[$field];
            }
            $body=file_get_contents('php://input');
            if ($body===false || strlen($body)>1000000) throw new InvalidArgumentException('Body too large or unreadable');
            $isCsv=isset($_SERVER['CONTENT_TYPE'])
                && stripos((string)$_SERVER['CONTENT_TYPE'],'text/csv')===0;
            $payload=$body==='' ? [] : ($isCsv
                ? GrTransportNetworkApi::parseCsv((string)($query['entity'] ?? ''),$body)
                : json_decode($body,true));
            if (!is_array($payload)) throw new InvalidArgumentException('JSON body must be an object');
            if ($isCsv) $query=['bulk'=>'1'];
            $response=GrTransportNetworkApi::handle($method,$query,$payload,$token);
            if ($method==='GET' && ($query['format'] ?? '')==='csv') {
                $entity=$query['entity'] ?? null;
                if (!is_string($entity) || !in_array($entity,GrTransportNetworkFields::ENTITIES,true)) {
                    throw new InvalidArgumentException('CSV export requires an entity');
                }
                $header=array_keys(GrTransportNetworkFields::fields($entity));
                $lines=[self::csvLine($header)];
                foreach ($response['items'] ?? [] as $row) {
                    $lines[]=self::csvLine(array_map(static function ($key) use ($row) {
                        return $row[$key] ?? '';
                    },$header));
                }
                header('Content-Type: text/csv; charset=utf-8');
                header('Cache-Control: no-store');
                echo implode("\r\n",$lines) . "\r\n";
                exit;
            }
        } catch (DomainException $e) {
            $status=$e->getCode()===403 ? 403 : 400;
            $response=['error'=>$e->getMessage()];
        } catch (InvalidArgumentException $e) {
            $status=400;
            $response=['error'=>$e->getMessage()];
        } catch (Throwable $e) {
            $status=500;
            $response=['error'=>'Network operation failed'];
            PrestaShopLogger::addLog('Network API: ' . $e->getMessage(),3);
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        exit;
    }

    private static function csvLine(array $values): string
    {
        return implode(',',array_map(static function ($value): string {
            // Always quote CSV fields; prevent spreadsheet formula evaluation.
            $text=(string)$value;
            if (preg_match('/^[=+@\-]/',$text)) $text="'".$text;
            return '"'.str_replace('"','""',$text).'"';
        },$values));
    }
}
