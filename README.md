# graylog-telemidia-php

Uma abstração da biblioteca [**gelf-php**](https://github.com/bzikarsky/gelf-php).

# Índice
- [Sobre](#sobre)
- [Instalação](#instalação)
- [Inicialização](#inicialização)
- [Utilização](#utilização)
- [Métodos suportados](#métodos-suportados)
- [Campos do Graylog](#campos-do-graylog)
- [Configuração de alertas do Graylog](#configuração-de-alertas-do-graylog)
- [Licença](#licença)

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

date_default_timezone_set('America/Sao_Paulo'); // Definir timezone para gerar logs com o horário local

use Telemidia\Graylog;

$GRAYLOG_CONFIG = [
    "server" => "graylog.myserver.com", // Endereço do servidor Graylog (obrigatório)
    "inputPort" => 12201, // Porta do input que receberá a mensagem (obrigatório)
    "appName" => "my-app", // Nome exibido no campo "facility" do Graylog (obrigatório)
    "appVersion" => "1.0.0", // Versão da aplicação (obrigatório)
    "environment" => "PROD", // Ambiente da aplicação: "PROD", "DEV" ou "STAGING" (obrigatório)
    "showConsole" => false // Define se o log será exibido no console da aplicação (opcional, padrão: true)
];

$graylog = new Graylog($GRAYLOG_CONFIG);
```

### 2. Configuração via variáveis de ambiente

Como alternativa, você pode definir a configuração através de variáveis de ambiente. As respectivas variáveis são:

* GRAYLOG_SERVER
* GRAYLOG_INPUT_PORT
* GRAYLOG_APP_NAME
* GRAYLOG_APP_VERSION
* GRAYLOG_ENVIRONMENT
* GRAYLOG_SHOW_CONSOLE

Neste caso, o objeto pode ser inicializado sem a necessidade de passar um parâmetro de configuração:

```php
$graylog = new Graylog();
```

As duas abordagens de configuração podem ser combinadas, de forma que os valores do parâmetro de configuração enviado terão preferência sobre os valores definidos através de variáveis de ambiente.

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

### Retorno

Os métodos retornam o payload com todas as informações que foram enviadas ao servidor Graylog.

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

## Campos do Graylog

Os campos enviados ao Graylog são os seguintes:

Campo | Descrição
--- | ---
app_language | linguagem de programação utilizada pela aplicação (PHP)
app_version | versão da aplicação - configurada durante a inicialização
environment | ambiente de execução da aplicação - configurado durante a inicialização
error_message | mensagem(ns) de erro coletada(s) através dos parâmetros extras (errors/exceptions)
error_stack | backtrace(s) do(s) erro(s) coletado(s)
extra_info | JSON contendo informações adicionais, enviadas como parâmetros extras
facility | nome da aplicação - configurado durante a inicialização
level | nível de severidade do log
message | mensagem principal do log
source | hostname do servidor que gerou o log
timestamp | carimbo de data e hora do log

## Configuração de alertas do Graylog

Para otimizar os alertas dos logs recebidos por meio desta biblioteca, recomendamos a implementação de algumas configurações específicas em relação aos alertas do Graylog. [Clique aqui para visualizar as recomendações de configuração](https://github.com/telemidia-isp/graylog-telemidia-php/blob/main/docs/GraylogAlerts.md).

## Licença

Este projeto está licenciado sob a Licença MIT.