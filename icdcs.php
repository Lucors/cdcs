<?php
    define("REG_JS", 0);
    define("ASYNC_JS", 1);
    define("PHP", 2);
    
    class InterpreterCDCS {
        public $version = "0.1";
        private $response = array();
        private $query = array();
        private $currentScriptname;
        private $scriptPtr;
        private $scriptOffset;
        private $readed;
        private $currentCommand;
        private $sCommandsMap;
        private $qCommandsMap;
        
        private $inlinePHPScript;
        private $inlineCDCScript = array();
        
        private $nonstop = array();
        private $inlineSyntaxType = [REG_JS];
        private $scriptAnon = false;


        //PUBLIC Funcs
        public function __construct(){
            $this->sCommandsMap = [
                "@test" => 'sCommandTest',
                "@nonstop" => 'sCommandNonstop',
                "@stop." => 'sCommandStop',
                "@sectionEnd." => 'sCommandSectionEnd',
                "@end." => 'sCommandSectionEnd',
                "@seek" => 'sCommandSeek',
                "@label" => 'sCommandLabel',
                "@goto" => 'sCommandGoto',
                "@asyncJS" => 'sCommandAsyncJS',
                "@async" => 'sCommandAsyncJS',
                "@php" => 'sCommandExecPHP',
                "@execPHP" => 'sCommandExecPHP',
                "@include" => 'sCommandInclude',
                "@exclude" => 'sCommandExclude',
                "@anon" => 'sCommandAnonInclude',
                "@anonin" => 'sCommandAnonInclude',
            ];
            $this->qCommandsMap = [
                "next" => 'qCommandNext',
                "goto" => 'qCommandGoto',
                "gotoFromStart" => 'qCommandGotoFromStart',
            ];
        }
        public function __destruct(){
           if ($this->scriptPtr){
               fclose($this->scriptPtr);
           }
        }
        public function error($message){
            $this->response["error"] = $message;
            $this->respond();
            die();
        }
        public function warning($message){
            $this->response["warnings"][] = $message;
        }
        public function doIt(){
            if (array_key_exists($this->query["command"], $this->qCommandsMap)){
                call_user_func([$this, $this->qCommandsMap[$this->query["command"]]]);
            }
            else {
                $this->error("Unknown command \"" . $this->query["command"] . "\"");
            }
        }
        public function step(){
            $this->readLine();
            $this->handleLine();
        }
        public function extraStep(){
            if (empty($this->nonstop)){
                return $this->step();
            }
        }
        public function handleLine($line = ""){
            if (!empty($line)){
                $this->readed = $line;
            }
            if ($this->readed[0] == '@'){
                $this->currentCommand = $this->getWord();
                if (array_key_exists($this->currentCommand, $this->sCommandsMap)){
                    return call_user_func([$this, $this->sCommandsMap[$this->currentCommand]]);
                }
                else {
                    $this->warning("Unknown command \"" . $this->currentCommand . "\"");
                    return false;
                }
            }
            else {
                $this->inlineSyntaxAllocator($this->readed);
                return true;
            }
        }
        public function respond(){
            if ($this->scriptPtr){
                $this->scriptOffset = ftell($this->scriptPtr);
                $this->response["scripts"] = $this->query["scripts"];
            }
            $this->response = json_encode($this->response);
            echo $this->response;
        }
        public function inlineSyntaxAllocator($exp){
            switch ($this->lastOf($this->inlineSyntaxType)){
                case REG_JS:
                    $this->pushExpression($this->readed);
                    break;
                case ASYNC_JS:
                    $this->pushExpression($this->readed, true);
                    break;
                case PHP:
                    $this->inlinePHPScript .= $this->readed;
                    break;
                default:
                    $this->warning("Invalid inline syntax type");
            }
        }
        public function pushExpression($exp, $async = false){
            if (empty($this->response["expressions"]) 
                    || end($this->response["expressions"])["async"] != $async){
                $this->response["expressions"][] = ["js" => $exp, "async" => $async];
            }
            else {
                $this->lastOf($this->response["expressions"])["js"] .= ' ' . $exp;
            }
        }
        public function pushInlineCDCS($line){
            $delimiterPos = strpos($line, '\n');
            if ($delimiterPos === false) {
                $this->inlineCDCScript[] = $line;
            } 
            else {
                $lines = explode('\n', $line);
                $this->inlineCDCScript = array_merge($this->inlineCDCScript, $lines);
            }
        }
        public static function &lastOf(&$array){
            return $array[count($array)-1];
        }
        
        //GET Funcs
        public function getCustomVar($key){
            if (array_key_exists($key, $this->query["customVars"])){
                return $this->query["customVars"][$key];
            }
            return null;
        }
        public function getReaded(){
            return $this->readed . ftell($this->scriptPtr);
        }
        public function getScriptOffset(){
            if ($this->scriptPtr){
                return ftell($this->scriptPtr);
            }
            return 0;
        }
        public function getResponse(){
            return $this->response;
        }
        public function getResponseAsStr(){
            return json_encode($this->response);
        }
        
        //SET Funcs
        public function setQuery($jsonQuery){
            $this->query = json_decode($jsonQuery, true);
            if (json_last_error() != JSON_ERROR_NONE){
                $this->error("Incorrect JSON " . json_last_error_msg());
            }
            $this->openScript(true);
        }
        
        //PROTECTED Funcs
        protected function getWord(){
            $pos = strpos($this->readed, " ");
            if ($pos !== false){
                $word = substr($this->readed, 0, $pos);
                $this->readed = substr($this->readed, $pos+1);
                return $word;
            }
            return $this->readed;
        }
        protected function readline($postProcessing = true){
            if ($this->readed = fgets($this->scriptPtr)){
                if (strpos($this->readed, PHP_EOL, -1) !== false){
                    $this->readed = str_replace(PHP_EOL, "", $this->readed);
                    $this->readed = str_replace("\r", "", $this->readed);
                }
                if ($postProcessing){
                    $this->readed = str_replace("\t", "", $this->readed);
                    if ((empty($this->readed) || $this->readed[0] == '#') && !feof($this->scriptPtr)){
                        return $this->readline($postProcessing);
                    }
                }
                return $this->readed;
            }
            else {
                if ($this->scriptAnon){
                    $this->excludeAnonScript();
                }
                else {
                    $this->error("End Of File");
                }
            }
        }
        protected function doInlinePHPScript(){
            try {
                eval($this->inlinePHPScript);
            }
            catch(Exception $ex){
                $this->error($ex->getMessage());
            }
            if (!empty($this->inlineCDCScript)){
                foreach ($this->inlineCDCScript as $line) {
                    $this->handleLine($line);
                }
                unset($this->inlineCDCScript);
            }
        }
        protected function openScript($throwErr = false){
            $this->currentScriptname = end($this->query["scripts"])["filename"];
            if (!($this->scriptPtr = fopen($this->currentScriptname . ".cdcs", 'r'))){
                $msg = "Unable open file \"" . $this->currentScriptname . ".cdcs\"";
                if ($throwErr){
                    $this->error($msg);
                }
                else {
                    $this->warning($msg);
                    return false;
                }
            }
            $this->offsetScript();
            return true;
        }
        protected function offsetScript($manualOffset = -1){
            if ($manualOffset < 0){
                $this->scriptOffset = &$this->lastOf($this->query["scripts"])["cursorPosition"];
            }
            else {
                $this->scriptOffset = $manualOffset;
            }
            if ($this->scriptOffset > -1){
                fseek($this->scriptPtr, 0, SEEK_END);
                $eofptr = ftell($this->scriptPtr);
                if (fseek($this->scriptPtr, $this->scriptOffset) < 0 || ftell($this->scriptPtr) >= $eofptr){
                    rewind($this->scriptPtr);
                    $this->warning("Invalid Cursor Position " . $this->scriptOffset);
                }
            }
        }
        protected function excludeAnonScript(){
            $this->scriptAnon = false;
            fclose($this->scriptPtr);
            $this->openScript();
        }
        
        //QCOMMANDS Funcs
        protected function qCommandNext(){
            try {
                do {
                    $this->step();
                }
                while (!empty($this->nonstop));
            }
            catch(Exception $ex){
                $this->error($ex->getMessage());
            }
        }
        protected function qCommandGoto(){
            if (array_key_exists("label", $this->query)){
                $this->sCommandGoto(true);
                $this->qCommandNext();
            }
            else {
                $this->error("Can't find label in Query");
            }
        }
        protected function qCommandGotoFromStart(){
            $preGotoOffset = ftell($this->scriptPtr);
            rewind($this->scriptPtr);
            if (!$this->qCommandGoto()){
                
            }
            $this->offsetScript($preGotoOffset);
        }
        
        //SCOMMANDS Funcs
        protected function sCommandTest(){
            $this->response["TEST"] = 'You get here by @test command';
        }
        protected function sCommandNonstop(){
            $this->nonstop[] = true;
        }
        protected function sCommandStop(){
            unset($this->nonstop);
        }
        protected function sCommandSectionEnd(){
            if (array_pop($this->nonstop) == null){
                $this->warning("There is no section to ended");
            }
            else {
                switch (array_pop($this->inlineSyntaxType)){
                    case PHP:
                        if (!in_array(PHP, $this->inlineSyntaxType)) {
                            $this->doInlinePHPScript();
                        }
                        break;
                }
            }
        }
        protected function sCommandSeek(){
            $offset = (int)$this->getWord();
            $whence = (int)$this->getWord();
            if (empty($whence) || ($whence > 2 || $whence < 0)) {
                $this->warning("Invalid whence parameter");
                $whence = 0;
            }
            fseek($this->scriptPtr, $offset, $whence);
        }
        protected function sCommandLabel(){
            $this->extraStep();
        }
        protected function sCommandGoto($fromQComm = false){
            $labelName = "";
            if ($fromQComm){
                $labelName = $this->query["label"];
            }
            else {
                $labelName = $this->getWord();
            }
            $curOffset = ftell($this->scriptPtr);
            do {
                $this->readline(false);
                if ($this->readed == ("@label ".$labelName)){
                    if (!$fromQComm){
                        $this->extraStep();
                    }
                    return true;
                }
            }
            while (!feof($this->scriptPtr));
            $this->warning("Bad Goto \"".$labelName."\"");
            fseek($this->scriptPtr, $curOffset);
            return false;
        }
        protected function sCommandAsyncJS(){
            $this->sCommandNonstop();
            $this->inlineSyntaxType[] = ASYNC_JS;
        }
        protected function sCommandExecPHP(){
            $this->sCommandNonstop();
            $this->inlineSyntaxType[] = PHP;
        }
        protected function sCommandInclude(){
            $this->scriptOffset = ftell($this->scriptPtr);
            $newScriptname = $this->getWord();
            $this->query["scripts"][] = ["filename" => $newScriptname, "cursorPosition" => 0];
            fclose($this->scriptPtr);
            if (!$this->openScript()){
                array_pop($this->query["scripts"]);
                $this->openScript();
            }
        }
        protected function sCommandExclude(){
            $delScriptName = $this->getWord();
            if ($delScriptName == "this" || $delScriptName == end($this->query["scripts"])["filename"]){
                if ($this->scriptAnon){
                    $this->excludeAnonScript();
                }
                else {
                    $this->scriptOffset = ftell($this->scriptPtr);
                    $backup = array_pop($this->query["scripts"]);
                    if (!empty($this->query["scripts"])){
                        fclose($this->scriptPtr);
                        $this->openScript();
                    }
                    else {
                        $this->warning("Can't exclude this");
                        $this->query["scripts"][] = $backup;
                        //Восстановление утерянной ссылки 
                        $this->scriptOffset = &$this->lastOf($this->query["scripts"])["cursorPosition"];
                    }
                }
            }
            else {
                for ($i = 0; $i < count($this->query["scripts"]); $i++){
                    if ($this->query["scripts"][i]["filename"] == $delScriptName){
                        array_splice($this->query["scripts"], i, 1);
                        return;
                    }
                }
                $this->warning("Can't exclude \"".$delScriptName."\"");
            }
        }
        protected function sCommandAnonInclude(){
            $this->currentScriptname = $this->getWord();
            if (!$this->scriptAnon){
                $this->scriptOffset = ftell($this->scriptPtr);
            }
            fclose($this->scriptPtr);
            if (!($this->scriptPtr = fopen($this->currentScriptname . ".cdcs", 'r'))){
                $this->openScript();
                $this->warning("Unable open file \"" . $this->currentScriptname . ".cdcs\"");
            }
            $this->scriptAnon = true;
            $this->extraStep();
        }
    }

    $interpreter = new InterpreterCDCS();
    if (!empty($_POST)){
       	$interpreter->setQuery($_POST['jsonQuery']);
        $interpreter->doIt();
        echo $interpreter->respond();
    }
    else {
       $interpreter->error("Empty Query");
    }
?>