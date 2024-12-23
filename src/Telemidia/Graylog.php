<?php

namespace Telemidia;

/**
 * Class Telemidia\Graylog
 * 
 * Esta classe fornece uma interface para enviar logs para um servidor Graylog.
 */
class Graylog
{
    private static ?self $instance = null;
    private string $appName;
    private string $environment;
    private \Gelf\Logger $logger;

    /**
     * Telemidia\Graylog constructor.
     * 
     * @param array|null $GRAYLOG_CONFIG Configurações do Graylog.
     * @throws InvalidArgumentException Se as configurações necessárias não forem fornecidas.
     */
    public function __construct(array $GRAYLOG_CONFIG = null)
    {
        $GRAYLOG_CONFIG = $GRAYLOG_CONFIG ?? $this->getDefaultConfig();
        $this->validateConfig($GRAYLOG_CONFIG);

        if (self::$instance === null) {
            $this->initializeLogger($GRAYLOG_CONFIG);
            self::$instance = $this; // Armazena a instância atual
        } else {
            $this->logger = self::$instance->logger; // Usa a instância existente
        }
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
            'environment' => getenv('GRAYLOG_ENVIRONMENT')
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
        $requiredKeys = ['server', 'inputPort', 'appName', 'environment'];
        foreach ($requiredKeys as $key) {
            if (empty($config[$key])) {
                throw new \InvalidArgumentException("A configuração '$key' é obrigatória.");
            }
        }
    }

    /**
     * Inicializa o logger com as configurações fornecidas.
     * 
     * @param array $GRAYLOG_CONFIG
     */
    private function initializeLogger(array $GRAYLOG_CONFIG): void
    {
        $transport = new \Gelf\Transport\UdpTransport($GRAYLOG_CONFIG['server'], $GRAYLOG_CONFIG['inputPort'], \Gelf\Transport\UdpTransport::CHUNK_SIZE_LAN);
        $publisher = new \Gelf\Publisher();
        $publisher->addTransport($transport);
        $this->logger = new \Gelf\Logger($publisher);
        $this->appName = $GRAYLOG_CONFIG['appName'];
        $this->environment = $GRAYLOG_CONFIG['environment'];
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
        $payload = $this->preparePayload($args);
        $message = $this->extractMessage($args);

        if (method_exists($this->logger, $method)) {
            return call_user_func_array([$this->logger, $method], [$message, $payload]);
        }

        throw new \BadMethodCallException("Método '$method' não existe.");
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

        return $this->buildPayload(
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
     * Lida com um argumento do tipo array.
     * 
     * @param array $arg
     * @param array $errorMessages
     * @param array $backTraces
     * @param array $arguments
     */
    private function handleArrayArgument(array $arg, array &$errorMessages, array &$backTraces, array &$arguments): void
    {
        foreach ($arg as $key => $value) {
            if ($value instanceof \Error || $value instanceof \Exception) {
                $this->handleError($value, $errorMessages, $backTraces);
                unset($arg[$key]);
            }
        }

        if (!empty($arg)) {
            $arguments[] = $arg;
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

        return count($backTraces) === 1 ? $backTraces[0]['backTrace'] : implode("", array_map(fn($index, $backtrace) => "[Backtrace do erro #$index '$backtrace[error]']:\n$backtrace[backTrace]\n\n", array_keys($backTraces), $backTraces));
    }

    /**
     * Constrói o payload final para o log.
     * 
     * @param string|null $jsonArguments
     * @param string|null $errorMessages
     * @param string|null $backTraces
     * @return array
     */
    private function buildPayload(?string $jsonArguments, ?string $errorMessages, ?string $backTraces): array
    {
        $payload = [
            'app_language' => 'PHP',
            'facility' => $this->appName,
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
