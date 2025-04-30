<?php

namespace Modules\TelegramBot\Traits;

use Illuminate\Support\Facades\Log;
use Modules\TelegramBot\Entities\Actors;


trait UsesTelegramBot
{
    public $telegram;
    public $message;
    public $actor;
    public $reply;

    public $ActorsController;
    public $TelegramController;

    public function receiveMessage()
    {
        $this->message = array();

        //var_dump(request("message"));

        // Preparando la informacion recibida en el $request en el mensaje del controlador correspondiente
        $type = "none";
        if (request("message")) {
            $type = "message";
            $this->message = request("message");
            if (!isset($this->message["text"])) {
                $this->message["text"] = "";
            }

        }
        if (request("callback_query")) {
            $type = "callback_query";
            $this->message = request("callback_query")["message"];
            $this->message["from"]["id"] = request("callback_query")["from"]["id"];
            $this->message["text"] = request("callback_query")["data"];
            if (isset(request("callback_query")["from"]["username"])) {
                $this->message["from"]["username"] = request("callback_query")["from"]["username"];
            }
        }
        //var_dump($this->message);
        // Escribiendo en el Log lo recibido para debug
        $log = "TelegramBotController {$type} for " . $this->telegram["username"];
        $logfrom = "";
        if (isset($this->message['chat'])) {
            $logfrom = " {$this->message['chat']['type']} {$this->message['chat']['id']}: ";
        } else {
            //(no chat info)
            return response()->json(["message" => "OK"], 200);
        }
        Log::info("{$log} from {$logfrom}" . json_encode($this->message));

        // analizando la informacion del texto obtenido en el $request
        // no se hace una validacion de $this->message["text"] vacio pues afecta en elvio de archivos a GutoTradeBot
        $textinfo = $this->getCommand($this->message["text"]);

        // Obteniendo al actor suscrito o suscribiedolo si no lo esta
        $this->actor = $this->ActorsController->suscribe($this, $this->telegram["username"], $this->message["from"]["id"], $textinfo["message"]);

        // si trae pinned_message significa q solo se esta tratando de pinear un mensaje y no lleva respuesta
        if (isset($this->message["pinned_message"]))
            return response()->json(["message" => "OK"], 200);

        // Finalmente se procesa la peticion recibida
        $this->reply = $this->processMessage();
        $log = "TelegramBotController {$type} reply from " . $this->telegram["username"];
        Log::info("{$log} to {$logfrom}" . json_encode($this->reply) . "\n");
        // Armando la respuesta correspondiente:
        $array = array(
            "message" => array(
                "text" => isset($this->reply["text"]) ? $this->reply["text"] : "",
                "photo" => isset($this->reply["photo"]) ? $this->reply["photo"] : false,
                "chat" => array(
                    "id" => $this->message["chat"]["id"],
                ),
                "reply_to_message_id" => isset($this->actor->data[$this->telegram["username"]]["config_delete_prev_messages"]) ? false : $this->message["message_id"], // responder al mensaje q origino esta interaccion del bot si no es dvzambrano
                "reply_markup" => isset($this->reply["markup"]) ? $this->reply["markup"] : false,
            ),
            "demo" => request("demo"),
        );
        if (isset($this->reply["photo"])) {
            $this->TelegramController->sendPhoto($array, $this->getToken($this->telegram["username"]));
        } else {
            // solo se envia un mensaje si tiene text
            // antes estaba $this->message["text"] pero lo cambie para q mandara el error cuando mandan la captura de un pago sin nombre y cantidad
            if (isset($this->reply["text"]) && $this->reply["text"] != "") {
                $autodestroy = 0;
                if (isset($this->reply["autodestroy"]) && $this->reply["autodestroy"] > 0) {
                    $autodestroy = $this->reply["autodestroy"];

                    //eliminando el mensaje q origino este de autoeliminacion
                    $bot_token = $this->getToken($this->telegram["username"]);
                    $controller = $this;
                    dispatch(function () use ($controller, $bot_token) {
                        $array = array(
                            "message" => array(
                                "id" => $this->message["message_id"],
                                "chat" => array(
                                    "id" => $this->message["chat"]["id"],
                                ),
                            ),
                        );
                        $controller->TelegramController->deleteMessage($array, $bot_token);
                    })->delay(now()->addMinutes($autodestroy));
                }
                $this->TelegramController->sendMessage($array, $this->getToken($this->telegram["username"]), $autodestroy);
            }
        }

        // eliminar el mensaje q origino esta interaccion del bot
        if ($this->message["message_id"] != "" && isset($this->actor->data[$this->telegram["username"]]["config_delete_prev_messages"])) {
            $array = array(
                "message" => array(
                    "id" => $this->message["message_id"],
                    "chat" => array(
                        "id" => $this->message["chat"]["id"],
                    ),
                ),
            );
            $this->TelegramController->deleteMessage($array, $this->getToken($this->telegram["username"]));
        }

        // Envía una respuesta al servidor de Telegram para confirmar la recepción
        return response()->json(["message" => "OK"], 200);
    }

    public function getCommand($text)
    {
        $text = trim("{$text}");

        //comprobando q se haya recibido un comando estilo: /start 816767995
        $array = explode(" ", $text);
        $command = $array[0];
        if (count($array) > 1) {
            unset($array[0]);
            $message = implode(" ", $array);
        } else {
            $message = "";
        }

        //comprobando q se haya recibido un comando estilo: confirmation|promote2-123456|menu
        $array = explode("|", strtolower($command));
        if (count($array) > 1) {
            $command = $array[0];
        } else {
            //comprobando q se haya recibido un comando estilo: promote2-123456
            $array = explode("-", strtolower($command));
            $command = $array[0];
        }

        $response = array(
            "command" => $command,
            "message" => $message,
            "pieces" => $array,
        );
        unset($array[0]);
        $response["params"] = $array;

        return $response;
    }

    public function getAreYouSurePrompt($yes_method, $no_method, $message = false)
    {
        $text = "⚠️ *Solicitud de confirmación*\n";
        if ($message)
            $text .= "\n{$message}\n";
        $text .= "_CUIDADO: Esta acción no se puede revertir_\n\n" .
            "👇 ¿Está seguro que desea continuar?";

        return array(
            "text" => $text,
            "markup" => json_encode([
                "inline_keyboard" => [
                    [
                        ["text" => "👍 Si", "callback_data" => "{$yes_method}"],
                        ["text" => "❌ No", "callback_data" => "{$no_method}"],
                    ],
                ],
            ]),
        );
    }


    /**
     * Obtiene el path de la captura desde los datos del mensaje de Telegram que contiene photo o document
     * @return mixed|string
     */
    public function getScreenshotPath()
    {
        $path = "";
        // Verificar si el mensaje contiene una foto
        //{"message_id":51,"from":{"id":816767995,"is_bot":false,"first_name":"Donel","last_name":"Vazquez Zambrano","username":"dvzambrano","language_code":"es"},"chat":{"id":816767995,"first_name":"Donel","last_name":"Vazquez Zambrano","username":"dvzambrano","type":"private"},"date":1723318835,"photo":[{"file_id":"AgACAgEAAxkBAAMzZrfCM1dfdgT4ptmq3Nz9kkO5yRkAAn6uMRuLpcBFs6NcKnRPYtoBAAMCAANzAAM1BA","file_unique_id":"AQADfq4xG4ulwEV4","file_size":1508,"width":90,"height":90},{"file_id":"AgACAgEAAxkBAAMzZrfCM1dfdgT4ptmq3Nz9kkO5yRkAAn6uMRuLpcBFs6NcKnRPYtoBAAMCAANtAAM1BA","file_unique_id":"AQADfq4xG4ulwEVy","file_size":12583,"width":320,"height":320},{"file_id":"AgACAgEAAxkBAAMzZrfCM1dfdgT4ptmq3Nz9kkO5yRkAAn6uMRuLpcBFs6NcKnRPYtoBAAMCAAN4AAM1BA","file_unique_id":"AQADfq4xG4ulwEV9","file_size":28591,"width":640,"height":640}],"caption":"descripcion"}
        //{"message_id":773,"from":{"id":1269084609,"is_bot":false,"first_name":"Tangiro","last_name":"Kamado","username":"AZOR79","language_code":"es","is_premium":true},"chat":{"id":1269084609,"first_name":"Tangiro","last_name":"Kamado","username":"AZOR79","type":"private"},"date":1724020895,"photo":[{"file_id":"AgACAgEAAxkBAAIDBWbCeJ-xcYyXbQIhxyiGSVt_q3sIAAIvrjEbEC4ZRt4IrtftWk3wAQADAgADcwADNQQ","file_unique_id":"AQADL64xGxAuGUZ4","file_size":2084,"width":90,"height":67},{"file_id":"AgACAgEAAxkBAAIDBWbCeJ-xcYyXbQIhxyiGSVt_q3sIAAIvrjEbEC4ZRt4IrtftWk3wAQADAgADbQADNQQ","file_unique_id":"AQADL64xGxAuGUZy","file_size":31996,"width":320,"height":240},{"file_id":"AgACAgEAAxkBAAIDBWbCeJ-xcYyXbQIhxyiGSVt_q3sIAAIvrjEbEC4ZRt4IrtftWk3wAQADAgADeAADNQQ","file_unique_id":"AQADL64xGxAuGUZ9","file_size":127565,"width":800,"height":601},{"file_id":"AgACAgEAAxkBAAIDBWbCeJ-xcYyXbQIhxyiGSVt_q3sIAAIvrjEbEC4ZRt4IrtftWk3wAQADAgADeQADNQQ","file_unique_id":"AQADL64xGxAuGUZ-","file_size":193270,"width":1080,"height":812}]}
        if (isset(request("message")["photo"])) {
            $path = request("message")["photo"][count(request("message")["photo"]) - 1]["file_id"];
        }
        //{"message_id":545,"from":{"id":816767995,"is_bot":false,"first_name":"Donel","last_name":"Vazquez Zambrano","username":"dvzambrano","language_code":"es"},"chat":{"id":816767995,"first_name":"Donel","last_name":"Vazquez Zambrano","username":"dvzambrano","type":"private"},"date":1723930097,"document":{"file_name":"Screenshot_1.png","mime_type":"image\/png","thumbnail":{"file_id":"AAMCAQADGQEAAgIhZsEV8YFXKXzlqneDuxn4U25c66AAAgoFAAIEWghGlM-Y6BuUaSUBAAdtAAM1BA","file_unique_id":"AQADCgUAAgRaCEZy","file_size":14083,"width":320,"height":158},"thumb":{"file_id":"AAMCAQADGQEAAgIhZsEV8YFXKXzlqneDuxn4U25c66AAAgoFAAIEWghGlM-Y6BuUaSUBAAdtAAM1BA","file_unique_id":"AQADCgUAAgRaCEZy","file_size":14083,"width":320,"height":158},"file_id":"BQACAgEAAxkBAAICIWbBFfGBVyl85ap3g7sZ-FNuXOugAAIKBQACBFoIRpTPmOgblGklNQQ","file_unique_id":"AgADCgUAAgRaCEY","file_size":53239},"caption":"Javier Cuesta 20"}
        //{"message_id":53,"from":{"id":816767995,"is_bot":false,"first_name":"Donel","last_name":"Vazquez Zambrano","username":"dvzambrano","language_code":"es"},"chat":{"id":816767995,"first_name":"Donel","last_name":"Vazquez Zambrano","username":"dvzambrano","type":"private"},"date":1723319469,"document":{"file_name":"Eleggua.jpg","mime_type":"image\/jpeg","thumbnail":{"file_id":"AAMCAQADGQEAAzVmt8StjqGvKARzGdU36R-juS0WBQAC2wMAAoulwEXJyFuQpLnQBwEAB20AAzUE","file_unique_id":"AQAD2wMAAoulwEVy","file_size":15013,"width":196,"height":320},"thumb":{"file_id":"AAMCAQADGQEAAzVmt8StjqGvKARzGdU36R-juS0WBQAC2wMAAoulwEXJyFuQpLnQBwEAB20AAzUE","file_unique_id":"AQAD2wMAAoulwEVy","file_size":15013,"width":196,"height":320},"file_id":"BQACAgEAAxkBAAM1ZrfErY6hrygEcxnVN-kfo7ktFgUAAtsDAAKLpcBFychbkKS50Ac1BA","file_unique_id":"AgAD2wMAAoulwEU","file_size":73807}}
        if (isset(request("message")["document"])) {
            $path = request("message")["document"]["file_id"];
        }

        return $path;
    }

    public function notifyNotImplemented($user_id)
    {
        return array(
            "text" => "ℹ️ *Función no implementada*\n\n_Esta función aun no está lista. Estamos trabajando en ella para sacarla en los próximos días._\n\n👇 Qué desea hacer ahora?",
            "chat" => array(
                "id" => $user_id,
            ),
            "markup" => json_encode([
                "inline_keyboard" => [
                    [
                        ["text" => "↖️ Volver al menú principal", "callback_data" => "menu"],
                    ],

                ],
            ]),
        );
    }

    public function notifyUserWithNoRole($user_id, $array)
    {
        $actor = $this->AgentsController->getSuscriptor($this, $user_id, true);

        // notificando a los administradores de q hay un nuevo usuario por si quieren cambiarle el rol
        $admins = $this->ActorsController->getData(Actors::class, [
            [
                "contain" => true,
                "name" => "admin_level",
                "value" => [1],
            ],
        ], $this->telegram["username"]);

        array_push($array, [
            ["text" => "↖️ Volver al menú principal", "callback_data" => "menu"],
        ]);

        for ($i = 0; $i < count($admins); $i++) {
            $text = "🆕 *Nuevo usuario suscrito al bot*\n\n" . $actor->getTelegramInfo($this, "full_info") . "\n\n";
            if ($actor && $actor->id > 0 && isset($actor->data[$this->telegram["username"]]["parent_id"]) && $actor->data[$this->telegram["username"]]["parent_id"] > 0) {
                // obteniendo datos del usuario padre en telegram
                $parent = $this->AgentsController->getSuscriptor($this, $actor->data[$this->telegram["username"]]["parent_id"], true);
                $text .= "🫡 Invitado por:\n" . $parent->getTelegramInfo($this, "full_info") . "\n\n";
            }
            $text .= "👇 Qué desea hacer ahora?";

            $array = array(
                "message" => array(
                    "text" => $text,
                    "chat" => array(
                        "id" => $admins[$i]->user_id,
                    ),
                    "reply_markup" => json_encode([
                        "inline_keyboard" => isset($array["menu"]) ? $array["menu"] : null,
                    ]),
                ),
            );
            $this->TelegramController->sendMessage($array, $this->getToken($this->telegram["username"]));
        }
    }

    public function notifyUsernameRequired($user_id)
    {
        $text = "ℹ️ *Para usar este bot, por favor configura un nombre de usuario (@username) en tu cuenta de Telegram*\n\n" .
            "🤔 *¿Cómo configurarlo?*\n\n" .
            "1️⃣ Ve a Configuración (o Ajustes).\n" .
            "2️⃣ Selecciona tu perfil y busca la opción Nombre de usuario.\n" .
            "3️⃣ Elige un nombre único que comience con @.\n\n" .
            "Una vez que hayas configurado tu nombre de usuario, haz clic en el siguiente botón:";
        return array(
            "text" => $text,
            "chat" => array(
                "id" => $user_id,
            ),
            "markup" => json_encode([
                "inline_keyboard" => [
                    [
                        ["text" => "👍 Listo, ya lo he hecho!", "callback_data" => "menu"],
                    ],

                ],
            ]),
        );
    }
}
