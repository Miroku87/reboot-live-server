<?php

$path = $_SERVER['DOCUMENT_ROOT'] . "/reboot-live-api/src/";
include_once($path . "classes/APIException.class.php");
include_once($path . "classes/UsersManager.class.php");
include_once($path . "classes/DatabaseBridge.class.php");
include_once($path . "classes/SessionManager.class.php");
include_once($path . "classes/Utils.class.php");
include_once($path . "config/constants.php");

class NewsManager
{
    protected $db;
    protected $session;
    
    public static $MAPPA_PAGINA_ABILITA = [
        26 => "Informazioni Commerciali",
        75 => "Contatti nell'Ago",
        81 => "Contatti tra gli Sbirri",
        77 => "Contatti nella Malavita",
        82 => "Contatti nella Famiglia"
    ];
    
    public function __construct()
    {
        $this->session = SessionManager::getInstance();
        $this->db = new DatabaseBridge();
    }
    
    public function __destruct()
    {
    }
    
    private function controllaInputArticolo( $tipo, $titolo, $autore, $pub_manuale, $data_pub, $ora_pub, $testo )
    {
        $error      = "";
    
        if( !isset($tipo) || $tipo === "-1" )
            $error .= "<li>&Egrave; obbligatorio scegliere il tipo dell'articolo.</li>";
    
        if( !isset($titolo) || Utils::soloSpazi($titolo) )
            $error .= "<li>Il campo Titolo non pu&ograve; essere lasciato vuoto.</li>";
        
        if( !isset($autore) || Utils::soloSpazi($autore) )
            $error .= "<li>Il campo Autore non pu&ograve; essere lasciato vuoto.</li>";
        
        if( isset($pub_manuale) && $pub_manuale === "0" && ( !isset($data_pub) || Utils::soloSpazi($data_pub) || !isset($ora_pub) || Utils::soloSpazi($ora_pub) ) )
            $error .= "<li>Se la pubblicazione &egrave; automatica &egrave; obbligatorio inserire data e ora di pubblicazione.</li>";
    
        if( !isset($testo) || Utils::soloSpazi($testo) )
            $error .= "<li>Il Testo dell'Articolo non pu&ograve; essere lasciato vuoto.</li>";
    
        return $error;
    }
    
    private function azioneNotizia( $tipo, $titolo, $autore, $data_ig, $pub_manuale, $data_pub, $ora_pub, $testo, $id_articolo = NULL )
    {
        $errors = $this->controllaInputArticolo( $tipo, $titolo, $autore, $pub_manuale, $data_pub, $ora_pub, $testo );
        
        if( !empty($errors) )
            throw new APIException( "Sono stati riscontrati i seguenti errori: <ul>$errors</ul>" );
        
        $macro_data = "NULL";
        $params = [
            ":tipo" => $tipo,
            ":titolo" => $titolo,
            ":autore" => $autore,
            ":dataig" => $data_ig,
            ":testo" => $testo
        ];
        
        if( $pub_manuale === "0" )
        {
            $datetime = DateTime::createFromFormat("d/m/Y H:i", $data_pub." ".$ora_pub );
            $time_str = $datetime->format("Y-m-d H:i:s" );
            
            $params[":data_pub"] = $time_str;
            $macro_data = ":data_pub";
        }
    
        if ( !isset($id_articolo) )
        {
            $query = "INSERT INTO notizie (id_notizia, tipo_notizia, titolo_notizia, autore_notizia, data_ig_notizia, data_pubblicazione_notizia, testo_notizia)
                            VALUES ( NULL, :tipo, :titolo, :autore, :dataig, $macro_data, :testo)";
        }
        else if ( isset($id_articolo) )
        {
            $query = "UPDATE notizie SET tipo_notizia = :tipo,
                                         titolo_notizia = :titolo,
                                         autore_notizia = :autore,
                                         data_ig_notizia = :dataig,
                                         data_pubblicazione_notizia = $macro_data,
                                         testo_notizia = :testo WHERE id_notizia = :id";
            $params[":id"] = $id_articolo;
        }
        
        $this->db->doQuery( $query, $params, False );
        
        $output = ["status" => "ok"];
        return json_encode($output);
    }
    
    public function creaNotizia( $tipo, $titolo, $autore, $data_ig, $pub_manuale, $data_pub, $ora_pub, $testo )
    {
        UsersManager::operazionePossibile( $this->session, __FUNCTION__ );
        return $this->azioneNotizia( $tipo, $titolo, $autore, $data_ig, $pub_manuale, $data_pub, $ora_pub, $testo );
    }
    
    public function modificaNotizia( $tipo, $titolo, $autore, $data_ig, $pub_manuale, $data_pub, $ora_pub, $testo, $id_art )
    {
        UsersManager::operazionePossibile( $this->session, __FUNCTION__ );
        return $this->azioneNotizia( $tipo, $titolo, $autore, $data_ig, $pub_manuale, $data_pub, $ora_pub, $testo, $id_art );
    }
    
    public function recuperaNotizie( $id = null, $tipo = null )
    {
        UsersManager::operazionePossibile( $this->session, __FUNCTION__ );
        
        if( isset($id) )
        {
            $params = [":id"=>$id];
            $query_sel = "SELECT * FROM notizie WHERE id_notizia = :id";
        }
        elseif ( isset($tipo) )
        {
            $params = [":tipo"=>$tipo];
            $query_sel = "SELECT * FROM notizie WHERE tipo_notizia = :tipo";
        }
        else if ( !isset($id) && !isset($tipo) )
        {
            $params = [":tipo"=>$tipo];
            $query_sel = "SELECT * FROM notizie";
        }
        
        $result = $this->db->doQuery($query_sel, $params, False);
        $output = [ ":status" => "ok", "result" => $result ];
        
        return json_encode($output);
    }
    
    public function recuperaNotiziePubbliche( $tipi = null )
    {
        UsersManager::operazionePossibile( $this->session, __FUNCTION__ );
        
        if( !isset($this->session->pg_loggato) )
            throw new APIException("Devi essere loggato con un personaggio per compiere questa operazione.", APIException::$GRANTS_ERROR);
        
        if ( !isset($tipi) )
        {
            $ids_civile   = Utils::mappaArrayDiArrayAssoc($this->session->pg_loggato["abilita"]["civile"], "id_abilita");
            $ids_militare = Utils::mappaArrayDiArrayAssoc($this->session->pg_loggato["abilita"]["militare"], "id_abilita");
            $ids          = array_merge($ids_civile, $ids_militare);
            $id_con_pag   = Utils::filtraArrayConValori( $ids, array_keys(self::$MAPPA_PAGINA_ABILITA) );
            $pagine       = Utils::filtraArrayConChiavi( self::$MAPPA_PAGINA_ABILITA, $id_con_pag );
            $tipi         = array_values($pagine);
        }
        else if ( isset($tipi) && !is_array($tipi) )
        {
            $tipi = [$tipi];
        }
        
        $marcatori = str_repeat("?, ", count( $tipi ) - 1 ) . "?";
        $query_sel = "SELECT * FROM notizie WHERE tipo_notizia IN ($marcatori) AND pubblica_notizia = 1";
        $result = $this->db->doQuery($query_sel, $tipi, False);
        $result = !isset( $result ) ? [] : $result;
        $output = [ ":status" => "ok", "result" => $result ];
        
        return json_encode($output);
    }
    
    public function pubblicaNotizia( $id )
    {
        UsersManager::operazionePossibile( $this->session, __FUNCTION__ );
        
        $query = "UPDATE notizie SET pubblica_notizia = :pub WHERE id_notizia = :id";
        $params = [":id" => $id, ":pub" => 1];

        $this->db->doQuery( $query, $params, False );
        
        $output = ["status" => "ok"];
        return json_encode($output);
    }
}