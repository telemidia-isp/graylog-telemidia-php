<?php

namespace Telemidia;

use Gelf\Logger as GelfLogger;
use Gelf\Publisher;
use Gelf\Transport\UdpTransport;
use InvalidArgumentException;
use BadMethodCallException;

/**
 * Class Telemidia\Graylog
 * 
 * Esta classe fornece uma interface para enviar logs para um servidor Graylog.
 * 
 * Exemplo de uso:
 * 
 * $graylog = new Graylog([
 *     'server' => 'graylog.myserver.com',
 *     'inputPort' => 12201,
 *     'appName' => 'my-app',
 *     'appVersion' => '1.0.0',
 *     'environment' => 'PROD',
 *     'showConsole' => true
 * ]);
 * 
 * $graylog->error('Ocorreu um erro horrível');
 */
class Graylog
{
    private static ?self $instance = null;
    private string $appName;
    private string $appVersion;
    private string $environment;
    private bool $showConsole;
    private string $timestamp;
    private GelfLogger $logger;

    /**
     * Telemidia\Graylog constructor.
     * 
     * @param array|null $GRAYLOG_CONFIG Configurações do Graylog.
     * @throws InvalidArgumentException Se as configurações necessárias não forem fornecidas.
     */
    public function __construct(array $GRAYLOG_CONFIG = null)
    {
        $GRAYLOG_CONFIG = array_merge($this->getDefaultConfig(), $GRAYLOG_CONFIG ?? []);
        $this->normalizeConfig($GRAYLOG_CONFIG);
        $this->validateConfig($GRAYLOG_CONFIG);

        if (self::$instance === null) {
            $this->initializeLogger($GRAYLOG_CONFIG);
            self::$instance = $this; // Armazena a instância atual
        } else {
            $this->logger = self::$instance->logger; // Usa a instância existente
        }
    }

    /**
     * Normaliza as configurações do Graylog.
     * 
     * @param array $GRAYLOG_CONFIG
     */
    private function normalizeConfig(array &$GRAYLOG_CONFIG): void
    {
        $GRAYLOG_CONFIG['showConsole'] = $this->normalizeShowConsole($GRAYLOG_CONFIG['showConsole']);
        $GRAYLOG_CONFIG['inputPort'] = $this->normalizeInputPort($GRAYLOG_CONFIG['inputPort']);
    }

    /**
     * Normaliza a configuração 'showConsole'.
     * 
     * @param mixed $showConsole
     * @return bool
     */
    private function normalizeShowConsole($showConsole): bool
    {
        return is_string($showConsole) ? strtolower($showConsole) !== 'false' : (bool)$showConsole;
    }

    /**
     * Normaliza a configuração 'inputPort'.
     * 
     * @param mixed $inputPort
     * @return int
     */
    private function normalizeInputPort($inputPort): int
    {
        return is_string($inputPort) ? (int)$inputPort : $inputPort;
    }

    /**
     * Obtém as configurações padrão do Graylog a partir das variáveis de ambiente.
     * 
     * @return array
     */
    private function getDefaultConfig(): array
    {
        return [
            'server' => getenv('GRAYLOG_SERVER'),
            'inputPort' => getenv('GRAYLOG_INPUT_PORT'),
            'appName' => getenv('GRAYLOG_APP_NAME'),
            'appVersion' => getenv('GRAYLOG_APP_VERSION'),
            'environment' => getenv('GRAYLOG_ENVIRONMENT'),
            'showConsole' => getenv('GRAYLOG_SHOW_CONSOLE') !== false ? getenv('GRAYLOG_SHOW_CONSOLE') : true
        ];
    }

    /**
     * Valida as configurações fornecidas.
     * 
     * @param array $config
     * @throws InvalidArgumentException Se as configurações necessárias não forem fornecidas.
     */
    private function validateConfig(array $config): void
    {
        $requiredKeys = ['server', 'inputPort', 'appName', 'appVersion', 'environment'];
        foreach ($requiredKeys as $key) {
            if (empty($config[$key])) {
                throw new InvalidArgumentException("A configuração '$key' é obrigatória.");
            }
        }

        $allowedEnvironments = ['PROD', 'DEV', 'STAGING'];
        if (!in_array($config['environment'], $allowedEnvironments)) {
            throw new InvalidArgumentException("A configuração 'environment' deve ser uma das seguintes: " . implode(', ', $allowedEnvironments));
        }
    }

    /**
     * Inicializa o logger com as configurações fornecidas.
     * 
     * @param array $GRAYLOG_CONFIG
     */
    private function initializeLogger(array $GRAYLOG_CONFIG): void
    {
        $transport = new UdpTransport($GRAYLOG_CONFIG['server'], $GRAYLOG_CONFIG['inputPort']);
        $publisher = new Publisher();
        $publisher->addTransport($transport);
        $this->logger = new GelfLogger($publisher);
        $this->appName = $GRAYLOG_CONFIG['appName'];
        $this->appVersion = $GRAYLOG_CONFIG['appVersion'];
        $this->environment = $GRAYLOG_CONFIG['environment'];
        $this->showConsole = $GRAYLOG_CONFIG['showConsole'];
    }

    /**
     * Método mágico para chamar métodos do logger.
     * 
     * @param string $method
     * @param array $args
     * @return mixed
     * @throws BadMethodCallException Se o método não existir.
     */
    public function __call(string $method, array $args)
    {
        $this->timestamp = date('Y-m-d H:i:s'); // Define a timestamp do log
        $payload = $this->preparePayload($args);
        $message = $this->extractMessage($args);

        // Exibe os detalhes do log no console, caso especificado na configuração
        if ($this->showConsole) {
            $this->logToConsole($method, $message, $payload);
        }

        if (method_exists($this->logger, $method)) {
            call_user_func_array([$this->logger, $method], [$message, $payload]);

            // Retorna os dados que foram enviados ao Graylog, para debug
            return $this->buildResponsePayload($method, $message, $payload);
        }

        throw new BadMethodCallException("Método '$method' não existe.");
    }

    /**
     * Extrai a mensagem do primeiro argumento.
     * 
     * @param array $args
     * @return string
     */
    private function extractMessage(array $args): string
    {
        return ($args[0] instanceof \Error || $args[0] instanceof \Exception) ? $args[0]->getMessage() : $args[0];
    }

    /**
     * Prepara o payload para o log.
     * 
     * @param array $args
     * @return array
     */
    private function preparePayload(array $args): array
    {
        $arguments = [];
        $errorMessages = [];
        $backTraces = [];

        foreach ($args as $i => $arg) {
            if ($i === 0 && !($arg instanceof \Error || $arg instanceof \Exception)) {
                continue; // Ignora o primeiro parâmetro se não for uma mensagem de log
            }

            if ($arg instanceof \Error || $arg instanceof \Exception) {
                $this->handleError($arg, $errorMessages, $backTraces);
                continue;
            }

            if (is_array($arg)) {
                $this->handleArrayArgument($arg, $errorMessages, $backTraces, $arguments);
            } else {
                $arguments[] = $arg;
            }
        }

        return $this->buildLogPayload(
            $this->formatArguments($arguments),
            $this->formatErrorMessages($errorMessages),
            $this->formatBackTraces($backTraces)
        );
    }

    /**
     * Lida com um argumento do tipo Error ou Exception.
     * 
     * @param Error|Exception $arg
     * @param array $errorMessages
     * @param array $backTraces
     */
    private function handleError($arg, array &$errorMessages, array &$backTraces): void
    {
        $errorMessage = $arg->getMessage();
        $errorMessages[] = $errorMessage;
        $backTraces[] = [
            'error' => $errorMessage,
            'backTrace' => $arg->getTraceAsString()
        ];
    }

    /**
     * Lida com um argumento do tipo array processando-o e armazenando-o se não estiver vazio.
     * 
     * @param array $arg O array de entrada a ser processado.
     * @param array &$errorMessages Referência a um array que armazenará mensagens de erro.
     * @param array &$backTraces Referência a um array que armazenará informações de rastreamento de pilha.
     * @param array &$arguments Referência a um array onde o array processado será armazenado se não estiver vazio.
     */
    private function handleArrayArgument(array $arg, array &$errorMessages, array &$backTraces, array &$arguments): void
    {
        $this->processArray($arg, $errorMessages, $backTraces);

        if (!empty($arg)) {
            $arguments[] = $arg;
        }
    }

    /**
     * Processa um array recursivamente, tratando erros e exceções.
     * 
     * @param array &$array O array a ser processado. Este array é modificado diretamente.
     * @param array &$errorMessages Um array que armazena mensagens de erro geradas durante o processamento.
     * @param array &$backTraces Um array que armazena informações de rastreamento de pilha relacionadas a erros.
     */
    private function processArray(array &$array, array &$errorMessages, array &$backTraces): void
    {
        foreach ($array as $key => &$value) {
            if (is_array($value)) {
                // Chama recursivamente para processar sub-arrays
                $this->processArray($value, $errorMessages, $backTraces);
            } elseif ($value instanceof \Error || $value instanceof \Exception) {
                // Trata o erro e remove o índice
                $this->handleError($value, $errorMessages, $backTraces);
                unset($array[$key]);
            }
        }
    }

    /**
     * Formata os argumentos em JSON.
     * 
     * @param array $arguments
     * @return string|null
     */
    private function formatArguments(array $arguments): ?string
    {
        if (empty($arguments)) {
            return null;
        }

        return $this->prettyPrintJson(json_encode(count($arguments) === 1 && is_array($arguments[0]) ? $arguments[0] : $arguments));
    }

    /**
     * Formata as mensagens de erro.
     * 
     * @param array $errorMessages
     * @return string|null
     */
    private function formatErrorMessages(array $errorMessages): ?string
    {
        if (empty($errorMessages)) {
            return null;
        }

        return count($errorMessages) === 1 ? $errorMessages[0] : trim(implode(" | ", array_map(fn($index, $message) => "[Erro #" . ($index + 1) . "]: $message", array_keys($errorMessages), $errorMessages)));
    }

    /**
     * Formata os backtraces.
     * 
     * @param array $backTraces
     * @return string|null
     */
    private function formatBackTraces(array $backTraces): ?string
    {
        if (empty($backTraces)) {
            return null;
        }

        return count($backTraces) === 1 ? $backTraces[0]['backTrace'] : implode("", array_map(fn($index, $backtrace) => "[Backtrace do erro #" . ($index + 1) . " \"$backtrace[error]\"]:\n$backtrace[backTrace]\n\n", array_keys($backTraces), $backTraces));
    }

    /**
     * Constrói o payload final para o log.
     * 
     * @param string|null $jsonArguments
     * @param string|null $errorMessages
     * @param string|null $backTraces
     * @return array
     */
    private function buildLogPayload(?string $jsonArguments, ?string $errorMessages, ?string $backTraces): array
    {
        $payload = [
            'app_language' => 'PHP',
            'facility' => $this->appName,
            'app_version' => $this->appVersion,
            'environment' => $this->environment
        ];

        if ($errorMessages !== null) {
            $payload['error_message'] = $errorMessages;
        }
        if ($backTraces !== null) {
            $payload['error_stack'] = $backTraces;
        }
        if ($jsonArguments !== null) {
            $payload['extra_info'] = $jsonArguments;
        }

        return $payload;
    }

    /**
     * Exibe os detalhes do log no console, caso especificado na configuração (padrão: true)
     * 
     * @param string $level Nível do log (ex: emergency, alert, critical, error)
     * @param string $message Mensagem do log
     * @param array $payload Dados adicionais do log
     */
    private function logToConsole(string $level, string $message, array $payload): void
    {
        $consoleMessage = "========= GRAYLOG MESSAGE [$this->timestamp]: =========" . PHP_EOL;

        $consoleMessage .= "Application: $this->appName | Version: $this->appVersion | Environment: $this->environment" . PHP_EOL;

        $consoleMessage .= "[$level] \"$message\"" . PHP_EOL;

        if (isset($payload['error_message']) && $payload['error_message']) {
            $consoleMessage .= 'Error message: "' . trim($payload['error_message']) . '"' . PHP_EOL;
        }
        if (isset($payload['error_stack']) && $payload['error_stack']) {
            $consoleMessage .= "Backtrace:" . PHP_EOL . trim($payload['error_stack']) . PHP_EOL;
        }
        if (isset($payload['extra_info']) && $payload['extra_info']) {
            $consoleMessage .= "Extra info:" . PHP_EOL . trim($payload['extra_info']) . PHP_EOL;
        }

        $consoleMessage .= "================= END OF GRAYLOG MESSAGE =================" . PHP_EOL;

        // Verifica o nível do log e exibe a mensagem apropriada
        if (in_array($level, ['emergency', 'alert', 'critical', 'error'])) {
            error_log($consoleMessage);
        } else {
            echo $consoleMessage;
        }
    }

    /**
     * Cria o payload de resposta que será retornado após o envio do log.
     * 
     * @param string $level Nível do log (ex: emergency, alert, critical, error)
     * @param string $message Mensagem do log
     * @param array $payload Dados adicionais do log
     * 
     * @return array Payload de resposta do log
     */
    private function buildResponsePayload(string $level, string $message, array $payload): array
    {
        $responsePayload = [
            'timestamp' => $this->timestamp,
            'level' => $level,
            'message' => $message
        ];

        return array_merge($responsePayload, $payload);
    }

    /**
     * Formata uma string JSON para melhor legibilidade.
     * 
     * @param string $json A string JSON a ser formatada.
     * 
     * @return string A string JSON formatada.
     */
    public function prettyPrintJson(string $json): string
    {
        $result = '';
        $level = 0;
        $inQuotes = false;
        $inEscape = false;
        $endsLineLevel = null;
        $jsonLength = strlen($json);

        for ($i = 0; $i < $jsonLength; $i++) {
            $char = $json[$i];
            $newLineLevel = null;
            $post = "";

            if ($endsLineLevel !== null) {
                $newLineLevel = $endsLineLevel;
                $endsLineLevel = null;
            }

            if ($inEscape) {
                $inEscape = false;
            } elseif ($char === '"') {
                $inQuotes = !$inQuotes;
            } elseif (!$inQuotes) {
                switch ($char) {
                    case '}':
                    case ']':
                        $level--;
                        $endsLineLevel = null;
                        $newLineLevel = $level;
                        break;

                    case '{':
                    case '[':
                        $level++;
                    case ',':
                        $endsLineLevel = $level;
                        break;

                    case ':':
                        $post = " ";
                        break;

                    case " ":
                    case "\t":
                    case "\n":
                    case "\r":
                        $char = "";
                        $endsLineLevel = $newLineLevel;
                        $newLineLevel = null;
                        break;
                }
            } elseif ($char === '\\') {
                $inEscape = true;
            }

            if ($newLineLevel !== null) {
                $result .= "\n" . str_repeat("    ", $newLineLevel);
            }

            $result .= $char . $post;
        }

        return $result;
    }
}
