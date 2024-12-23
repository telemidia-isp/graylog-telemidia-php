# graylog-telemidia-php

Uma abstração da biblioteca [**gelf-php**](https://github.com/bzikarsky/gelf-php).

## Sobre

Este pacote fornece uma interface simples e eficiente para enviar mensagens de log para o Graylog, seguindo o padrão utilizado pela Telemidia.

## Instalação

Para instalar a biblioteca, utilize o composer:

```bash
composer require telemidia/graylog
```

## Inicialização

Para inicializar a biblioteca, você deve configurar as informações do Graylog. Existem duas abordagens para realizar essa configuração:

### 1. Configuração via array

Você pode configurar a biblioteca utilizando um array associativo. Veja um exemplo abaixo:

```php
require_once __DIR__ . '/path/to/vendor/autoload.php';

use Telemidia\Graylog;

$GRAYLOG_CONFIG = [
    "server" => "graylog-datanode.myserver.com",
    "inputPort" => 12201,
    "appName" => "my-app", // Nome que será exibido no campo "facility" do Graylog
    "environment" => "PROD" // Pode ser "PROD" ou "DEV"
]; // Todos os parâmetros são obrigatórios

$graylog = new Graylog($GRAYLOG_CONFIG);
```

### 2. Configuração via variáveis de ambiente

Como alternativa, você pode definir a configuração através de variáveis de ambiente. As variáveis necessárias são:

* GRAYLOG_SERVER
* GRAYLOG_INPUT_PORT
* GRAYLOG_APP_NAME
* GRAYLOG_ENVIRONMENT

Neste caso, o objeto pode ser inicializado sem a necessidade de passar um parâmetro de configuração:

```php
$graylog = new Graylog();
```

## Utilização

A biblioteca permite o envio de mensagens de log de forma simples e eficiente. Veja alguns exemplos de uso:

### Enviar uma mensagem simples

```php
$graylog->error('Ocorreu um erro horrível!');
```

### Informações adicionais

Para enriquecer as mensagens de log, você pode adicionar informações adicionais. Isso é útil para fornecer contexto sobre o erro:

```php
$userInfo = [
    "isUserAuthenticated" => true,
    "isUserAdmin" => false
];
$graylog->error('Ocorreu um erro horrível!', $userInfo);
```

### Backtrace

É possível registrar o backtrace de erros ou exceções. Para isso, basta passar o objeto de erro:

```php
try {
    throw new Error('Ocorreu um erro horrível!');
} catch (Error $e) {
    $graylog->error($e);
}
```

Você também pode personalizar a mensagem do erro, mantendo a mensagem original visível no Graylog:

```php
try {
    throw new Error('Ocorreu um erro horrível!');
} catch (Error $e) {
    $graylog->error('Ocorreu um erro em foo bar', $e);
}
```

### Combinação de informações

Também é possível enviar múltiplos parâmetros, permitindo um contexto mais detalhado:

```php
try {
    throw new Error('Ocorreu um erro horrível!');
} catch (Error $e) {
    $userInfo = [
        "isUserAuthenticated" => true,
        "isUserAdmin" => false
    ];
    $graylog->error('Ocorreu um erro em foo bar', $e, $userInfo);
}
```

## Métodos suportados

A biblioteca suporta diversos métodos para registrar logs, cada um correspondente a um nível de severidade no Graylog. Os métodos disponíveis são:

Método | Nível Graylog
--- | ---
emergency | 0
alert | 1
critical | 2
error | 3
warning | 4
notice | 5
info | 6
debug | 7

## Licença

Este projeto está licenciado sob a Licença MIT.