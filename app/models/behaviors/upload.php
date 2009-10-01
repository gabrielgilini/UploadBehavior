<?php
/* 
 * Upload Behavior
 * Behavior baseado no Meio Upload. Como o Meio Upload sofreu muitas mudanças
 * e ao meu ver ficou ruim, resolvi criar um Behavior que atenda minha necessida
 * para enviar arquivos e imagens. As imagens podem ser redimensionadas, usar crop, etc..
 *
 * Para gerar ThumbNails é necessário ter na sua pasta vendors a biblioteca phpThumb que pode ser
 * encontra no link: http://phpthumb.gxdlabs.com/
 * Descompacte o conteúdo da phpThumb na pasta vendors/phpThumb.
 *
 * Exemplo de uso:
 *
 * var $actsAs = array(
 *       'Upload' => array(
 *
 *        // Enviar uma imagem //
 *
 *          'imagem' => array(                                                                      //Nome do campo na base de dados.
 *              'dir' => 'img{DS}uploads{DS}marcas',                                                //Diretório para envio, apartir do webroot
 *              'default' => 'sem_logo.jpg',                                                        //Arquivo a ser exibido o campo esteja em branco na base.
 *               'allowedMime' => array('image/jpeg', 'image/pjpeg', 'image/png', 'image/gif'),     //Mimetype permitidos (Não setar se não querer validar)
 *               'allowedExt' => array('jpg', 'jpeg', 'png', 'gif'),                                //Extenções permitidas (Não setar se não querer validar)
 *               'sizes' => array(                                                                  //Se for uma imagem você pode setar o nome e os metodos e valores a serem aplicados na imagem da lib phpThumb
 *                   'small' => array(                                                              //vai gerar small_nomeDoArquivoEnviado.ext
 *                       'adaptiveResize' => array(200,100)                                         //Aplica o metodo adaptiveResize passando os parametros 200 e 100 da classe phpThumb
 *                   ),
 *                   'medium' => array(                                                             //vai gerar medium_nomeDoArquivoEnviado.ext
 *                       'cropFromCenter' => 200                                                    //Aplica o metodo cropFromCenter com o parametro 200 da classe phpThumb
 *                   )
 *               )
 *           ),
 *
 *        // Enviar arquivo //
 *
 *           'arquivo' => array(                                                                    //Nome do campo na base de dados
 *               'dir' => 'files{DS}uploads{DS}marcas',                                             //Diretório de envio
 *               'allowedExt' => array('doc','docx','pdf')                                          //Extensão permitidas
 *           )
 *       )
 *   );
 *
 * Lista de fun��es para usar na ao gerar o ThumbNail
 *
 *  * crop ($startX, $startY, $cropWidth, $cropHeight)
 *  * cropFromCenter ($cropWidth, $cropHeight = null)
 *  * resize ($maxWidth, $maxHeight)
 *  * resizePercent ($percent)
 *  * rotateImage ($direction = 'CW')
 *  * rotateImageNDegrees ($degrees)
 *
 * Os metodos para gerar thumbnail pode ser "combados".
 * Ex.: Definir tamanhos para gerar o thumbNail e rotacinar a imagem.
 * array('sizes' => array(
 *          'small' => array(
 *              'resize' => (300,300),
 *              'rotateImage('CW)
 *          )
 *      )
 * );
 * 
 * @author Daniel Luiz Pakuschewski
 * @author-url http://www.danielpk.com.br
 * @version 0.1
 * @lastmodified 2009-09-24
 */
App::import('Core', 'File');
App::import('Core', 'Folder');
class UploadBehavior extends ModelBehavior {

    
    private $__options = array(
        'dir' => '{modelName}{DS}{field}',
        'createDir' => true,
        'allowedMime' => array(),
        'allowedExt' => array(),
        'removeSource' => false,
        'default' => false,
        'maxSize' => 2097152, // 2MB
        'sizes' => array(),
    );

    private $__validate = array(
        'Empty' => array(
            'rule' => array('validateEmpty'),
            'message' => 'È necess�rio selecionar um arquivo.',
            'on' => 'create',
            'check' => true,
            'last' => true
        ),
        'Dir' => array(
            'rule' => array('validateDir'),
            'message' => 'Diret�rio n�o existe ou n�o tem permiss�o para escrita.',
            'check' => true,
            'last' => true
        ),
        'Type' => array(
            'rule' => array('validateType'),
            'message' => 'O arquivo enviado � inv�lido.',
            'check' => true,
            'last' => true
        ),
        'Size' => array(
            'rule' => array('validateSize'),
            'message' => array('Voc� est� enviando um arquivo maior que o permitido.'),
            'check' => true,
            'last' => true
        )
    );

    /**
     * Guarda um instancia do model
     * @var object
     */
    private $__model;

    /**
     * Guarda as configurações de cada arquivo
     * que será enviado
     * @var array
     */
    private $__files = array();

    /**
     * Armazene o caminho completo dos arquivos
     * que serão deletados depois que os dados
     * forem salvo no banco.
     * @var array
     */
    private $__filesToDelete = array();

    public function setup(&$model, $settings) {
        //Guarda uma instancia do model
        $this->__model = $model;
        
        foreach($settings as $field => $options){
            //Junta as duas opções
            $options = Set::merge($this->__options, $options);
            
            //Verifica se o campo informado existe na base de dados
            if (!$model->hasField($field)) {
                trigger_error(sprintf('UploadBehavior Error: O campo "%s" n�o existe na base de dados "%s".', $field, $model->alias), E_USER_WARNING);
            }

            if($options['default']) {
                if (!preg_match('/^.+\..+$/', $options['default'])) {
                    trigger_error('UploadBehavior Error: A op��o "default" tem que ter uma exten��o.', E_USER_ERROR);
                }
            }

            $options['dir'] =$this->__replaceTokens($options['dir'], $field);
            $this->__files[$field] = $options;
            unset($options);
        }
    }

    /* ---Validações--- */
    /**
     * Verifica se o diretório existe e tem permissão de escrita
     * dependendo das opções passadas cria o diretório
     */
    public function validateDir(&$model, $data) {
        foreach($data as $field => $_field) {
            if(empty($_field['name'])){ return true; }
            if (!$this->__model->validate[$field]['Dir']['check']) {
                return true;
            }
            $options = $this->__files[$field];
            $Folder = new Folder($this->__fullPathDir($options['dir']), $options['createDir'], 777);
            if(is_dir($Folder->path) && is_writable($Folder->path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Valida se algum arquivo foi enviado
     */
    public function validateEmpty(&$model, $data){
         foreach($data as $field => $_file) {
            if (!$this->__model->validate[$field]['Empty']['check']) {
                return true;
            }
            if(empty($_file['name'])){
                return false;
            }
            return true;
         }
    }
    
    /**
     * Verifiaca o se o tipo de arquivo enviado é o arquivo
     * esperado conforme o option.
     */
    public function validateType(&$model, $data) {
        foreach($data as $field => $_field) {
            if(empty($_field['name'])){ return true; }
            if (!$this->__model->validate[$field]['Type']['check']) {
                return true;
            }
            
            $options = $this->__files[$field];
            //Verifica MimeType
            if(!empty($options['allowedMime'])) {
                if(!in_array($_field['type'], $options['allowedMime'])) {
                    return false;
                }
            }
            if(!empty($options['allowedExt'])) {
            //Verifica extenção
                if(!in_array($this->__getExt($_field['name']), $options['allowedExt'])) {
                    return false;
                }
            }
        }
        return true;
    }

    public function validateSize(&$model, $data){
        foreach($data as $field => $_field) {
            if(empty($_field['name'])){ return true; }
            if (!$this->__model->validate[$field]['Size']['check']) {
                return true;
            }
            $options = $this->__files[$field];
            if(!$_field['name'] && $_field['size'] > $options['maxSize']){
                $model->validate[$field]['Size']['message'] = "O arquivo enviado(".$file['size'].") � maior do tamanho permitido(".$options['maxSize'].")";
                return false;
            }
            return true;
         }
    }
    /**/

    /* -- Callbacks -- */

    public function beforeValidate(&$model){
        foreach($this->__files as $field => $options){
            $array = array();
            $array[$field] = $this->__validate;
            $model->validate = Set::merge($model->validate, $array);
        }
    }

    public function beforeSave(&$model){
        $data = $model->data[$model->alias];
        foreach($this->__files as $field => $options){
            if(empty($data[$field]['name'])){
                unset($model->data[$model->alias][$field]);
                continue;
            }
            $file = $data[$field];
            $file = $this->__uploadFile($file, $this->__fullPathDir($options['dir']));
            if(!empty($options['sizes']) && count($options['sizes']) > 0){
                foreach($options['sizes'] as $tamanho => $setting){
                    if($tamanho == 'normal'){
                        $tamanho = null;
                        $this->__setToDelete($file);
                    }
                    $this->__generateThumbnail($file, $this->__fullPathDir($options['dir']), $tamanho, $setting);
                }
            }
            $model->data[$model->alias][$field] = $file;
        }
        return true;
    }

    public function afterSave($isCreated){
        $this->__deleteFiles();
    }

    public function beforeDelete(){
        foreach($this->__files as $field => $options){
            $arquivo = $this->__model->read($field);
            $arquivo = $arquivo[$this->__model->alias][$field];
            if(!empty($options['sizes']) && count($options['sizes']) > 0){
                foreach($options['sizes'] as $tamanho => $setting){
                    if($tamanho == 'normal'){
                        continue;
                    }
                    unlink($this->__fullPathDir($options['dir']).DS.$tamanho.'_'.$arquivo);
                }
            }
            unlink($this->__fullPathDir($options['dir']).DS.$arquivo);
        }
        return true;
    }

    /*
     * Se foi criado algum thumbnail ele adiciona
     * ao array do arquivo o nome do thumbnail.
     */
    public function afterFind(&$model, $data){
        foreach($this->__files as $field => $options){
            for( $a=0; $a<count($data); $a++){
                if(!empty($data[$a][$model->alias][$field])){
                    if(!empty($options['sizes'])){
                        foreach($options['sizes'] as $size => $methods){
                            $data[$a][$model->alias][$size.'_'.$field] = $size.'_'.$data[$a][$model->alias][$field];
                        }
                    }
                }elseif($options['default']){
                    $data[$a][$model->alias][$field] = $options['default'];
                }
            }
        }
        return $data;
    }

    /**
     * Guarda os arquivos para serem deletados
     * depois que for salvo.
     * @param string $file
     * @return void
     */
     private function __setToDelete($file){
         $this->__filesToDelete = Set::merge($this->__filesToDelete, array($file));
     }

     /**
      * Delete os arquivos que estão no $__filesToDelete
      */
     private function __deleteFiles(){
        foreach($this->__filesToDelete as $file){
            if(is_file($file)){
                unlink($file);
            }
        }
     }
    /**
     * Limpa o nome do arquivo e verifica se o arquivo ja existe
     * @param string $name
     * @param string $dir
     * return string
     */
    private function __uniqueName($name, $dir){
        $name = $this->__cleanName($name);
        $path = $dir.DS.$name;
        if(file_exists($path)){
            $name = time().$name;
        }
        return $name;
    }
    /**
     * Remove caracteres especiais e retorna em minusculo
     */
    private function __cleanName($string){
       $string = ereg_replace("[^a-zA-Z0-9_.]", "", $string);
       $string = strtolower($string);
       return $string;
    }

    /**
     * Retorna a extenção do arquivo
     * @param string $filename
     * @return string
     */
    private function __getExt($filename) {
        $filename = strtolower($filename);
        if(strpos($filename, '.jpeg')) {
            return 'jpeg';
        }
        $tamanho = strlen($filename);
        $ext = substr($filename, $tamanho - 3);

        return $ext;
    }

    /**
     * Retorna o caminho completo do DIR informado no options
     * @param string $dir
     * @return string
     */
    private function __fullPathDir($dir){
        return WWW_ROOT.$dir;
    }

    /**
     * Substitui os TOKENs colocados no dir
     * @param string $dir
     * @param string $field
     * @return string
     */
    private function __replaceTokens($dir, $field){
        $tokens = array('{modelName}','{DS}','{fieldName}');
        $replace = array(Inflector::underscore($this->__model->alias), DS, $field);
        return trim(str_replace($tokens, $replace, $dir));
    }

    /**
     * Cria o thumbnail de acordo com os paramentros passados nas options
     * necessita do Vendor phpThumb()
     * @param string $file
     * @param string $dir
     * @param string $prefix
     * @param array $setting
     */
    private function __generateThumbnail($file, $dir, $prefix = null, $setting = array()){
        App::import('Vendor','phpthumb', array('file' => 'phpThumb'.DS.'ThumbLib.inc.php'));
        $path = $dir.DS.$file;
        $thumb = PhpThumbFactory::create($path);
        foreach($setting as $metodo => $valores){
            $val = (is_array($valores)) ? implode(',', $valores) : $valores;
            eval("\$thumb->{$metodo}($val);");
        }
        
        if(is_null($prefix)){
            $thumb->save($dir.DS.$file);
        }else{
            $thumb->save($dir.DS.$prefix.'_'.$file);
        }
    }

    /**
     * Faz o Upload do Arquivo
     * @return mixed (false ou file name)
     */
    private function __uploadFile($file, $dir){
        $file['name'] = $this->__uniqueName($file['name'], $dir);
        $path = $dir.DS.$file['name'];
        if(!move_uploaded_file($file['tmp_name'], $path)){
            return false;
        }
        return $file['name'];
    }
}
?>