<?php
$path = $_SERVER['DOCUMENT_ROOT']."/reboot-live-api/src/";
include_once($path."classes/APIException.class.php");
include_once($path."classes/UsersManager.class.php");
include_once($path."classes/DatabaseBridge.class.php");
include_once($path."classes/SessionManager.class.php");

class MessagingManager
{
    protected $db;
    protected $session;
    protected $idev_in_corso;
    
    public function __construct( $idev_in_corso = NULL )
    {
        $this->idev_in_corso = $idev_in_corso;
        $this->session = SessionManager::getInstance();
        $this->db      = new DatabaseBridge();
    }
    
    public function __destruct()
    {
    }
    
    public function inviaMessaggio( $tipo, $mitt, $dest, $ogg, $mex, $risp_id = NULL  )
    {
        UsersManager::operazionePossibile( $this->session, __FUNCTION__ );
        
        $tabella       = $tipo === "fg" ? "messaggi_fuorigioco" : "messaggi_ingioco";
        $tabella_check = $tipo === "fg" ? "giocatori" : "personaggi";
        $id_check      = $tipo === "fg" ? "email_giocatore" : "id_personaggio";
        $name_check    = $tipo === "fg" ? "CONCAT(nome_giocatore,' ',cognome_giocatore) AS nome" : "nome_personaggio AS nome";
        $eliminato     = $tipo === "fg" ? "eliminato_giocatore" : "eliminato_personaggio";
        $dest_names    = [];
        
        if( count( $dest ) === 0 )
            throw new APIException("Non sono stati specificati dei destinatari.");
        
        $query_check = "SELECT $id_check, $name_check FROM $tabella_check WHERE $id_check = :mitt AND $eliminato = 0";
        $ris_check   = $this->db->doQuery( $query_check, array( ":mitt" => $mitt ), False );
        
        if( count( $ris_check ) === 0 )
            throw new APIException("Il mittente di questo messaggio non esiste.");
        
        foreach( $dest as $i => $d )
        {
            if ( empty($d) || $d == NULL )
                continue;
    
            $query_check = "SELECT $id_check, $name_check FROM $tabella_check WHERE $id_check = :dest AND $eliminato = 0";
            $ris_check = $this->db->doQuery($query_check, array(":dest" => trim($d)), False);
    
            if (count($ris_check) === 0)
                throw new APIException("Il destinatario $d di questo messaggio non esiste.");
            
            $dest_names[$i] = $ris_check[0]["nome"];
        }
        
        foreach( $dest as $i => $d )
        {
            if( empty($d) || $d == NULL )
                continue;
        
            $params = array(
                ":mitt" => $mitt,
                ":dest" => $d,
                ":ogg"  => $ogg,
                ":mex"  => $mex
            );
            
            if( isset( $risp_id ) )
            {
                $q_risp = ":risp";
                $params[":risp"] = $risp_id;
            }
            else
                $q_risp = "NULL";
            
            $query_mex = "INSERT INTO $tabella (mittente_messaggio, destinatario_messaggio, oggetto_messaggio, testo_messaggio, risposta_a_messaggio) VALUES ( :mitt, :dest, :ogg, :mex, $q_risp )";
            $this->db->doQuery( $query_mex, $params, False );
            
            $inviato_a[] = $dest_names[$i];
        }
        
        return json_encode([
            "status"  => "ok",
            "result"  => True,
            "message" =>"Messaggio inviato correttamente a ".implode(", ", $inviato_a)
        ]);
    }
    
    public function recuperaMessaggioSingolo( $idmex, $idu, $tipo, $casella )
    {
        UsersManager::operazionePossibile( $this->session, __FUNCTION__, $idu );
    
        $params     = array( ":idmex" => $idmex );
        $tabella    = $tipo === "ig" ? "messaggi_ingioco" : "messaggi_fuorigioco";
        $t_join     = $tipo === "ig" ? "personaggi" : "giocatori";
        $campo_id   = $tipo === "ig" ? "id_personaggio" : "email_giocatore";
        $campo_nome = $tipo === "ig" ? "nome_personaggio" : "nome_giocatore";
    
        $query_mex = "SELECT mex.*,
                             t_mitt.$campo_id AS id_mittente,
                             t_mitt.$campo_nome AS nome_mittente,
                             t_dest.$campo_id AS id_destinatario,
                             t_dest.$campo_nome AS nome_destinatario,
                             '$tipo' AS tipo_messaggio,
                             '$casella' AS casella_messaggio
                      FROM $tabella AS mex
                        JOIN $t_join AS t_mitt ON mex.mittente_messaggio = t_mitt.$campo_id
                        JOIN $t_join AS t_dest ON mex.destinatario_messaggio = t_dest.$campo_id
                      WHERE mex.id_messaggio = :idmex";
        $risultati  = $this->db->doQuery( $query_mex, $params, False );
		
        if(    ( is_array($this->session->pg_loggato) && $risultati[0]["id_destinatario"] === $this->session->pg_loggato["id_personaggio"] )
            || $risultati[0]["id_destinatario"] === $this->session->email_giocatore )
        {
            $query_letto = "UPDATE $tabella SET letto_messaggio = :letto WHERE id_messaggio = :id";
            $this->db->doQuery($query_letto, array(":id" => $idmex, ":letto" => 1), False);
        }
        
        return "{\"status\": \"ok\",\"result\": ".json_encode($risultati[0])."}";
    }
    
    public function recuperaMessaggi( $draw, $columns, $order, $start, $length, $search, $tipo, $casella, $id )
    {
        UsersManager::operazionePossibile( $this->session, __FUNCTION__, $id );

        $filter     = False;
        $params     = array( ":id" => $id );
        $where      = $casella === "inviati" ? "mex.mittente_messaggio = :id" : "mex.destinatario_messaggio = :id";
        $tabella    = $tipo === "ig" ? "messaggi_ingioco" : "messaggi_fuorigioco";
        $t_join     = $tipo === "ig" ? "personaggi" : "giocatori";
        $campo_id   = $tipo === "ig" ? "id_personaggio" : "email_giocatore";
        $campo_nome = $tipo === "ig" ? "nome_personaggio" : "nome_giocatore";

        if( isset( $search ) && $search["value"] != "" )
        {
            $filter = True;
            $params[":search"] = "%$search[value]%";
            $where .= " AND (
						t_mitt.$campo_nome LIKE :search OR
						t_dest.$campo_nome LIKE :search OR
						mex.oggetto_messaggio LIKE :search OR
						mex.testo_messaggio LIKE :search
					  )";
        }

        if( isset( $order ) )
        {
            $sorting = array();
            foreach ( $order as $elem )
                $sorting[] = $columns[$elem["column"]]["data"]." ".$elem["dir"];
    
            $order_str = "ORDER BY ".implode( $sorting, "," );
        }
        
        $query_mex = "SELECT mex.id_messaggio,
                             mex.oggetto_messaggio,
                             mex.data_messaggio,
                             mex.letto_messaggio,
                             t_mitt.$campo_id AS id_mittente,
                             t_mitt.$campo_nome AS nome_mittente,
                             t_dest.$campo_id AS id_destinatario,
                             t_dest.$campo_nome AS nome_destinatario,
                             '$tipo' AS tipo_messaggio,
                             '$casella' AS casella_messaggio
                      FROM $tabella AS mex
                        JOIN $t_join AS t_mitt ON mex.mittente_messaggio = t_mitt.$campo_id
                        JOIN $t_join AS t_dest ON mex.destinatario_messaggio = t_dest.$campo_id
                      WHERE $where $order_str";

        $risultati  = $this->db->doQuery( $query_mex, $params, False );
        $totale     = count($risultati);
        
        if( count($risultati) > 0 )
            $risultati = array_splice($risultati, $start, $length);
        else
            $risultati = array();

        $output     = array(
            "status"          => "ok",
            "draw"            => $draw,
            "columns"         => $columns,
            "order"           => $order,
            "start"           => $start,
            "length"          => $length,
            "search"          => $search,
            "recordsTotal"    => $totale,
            "recordsFiltered" => $filter ? count($risultati) : $totale,
            "data"            => $risultati
        );

        return json_encode( $output );
    }
    
    private function recuperaDestinatari( $tipo, $term  )
    {
        if( substr($term, 0 ,1) === "#" )
            return json_encode([ "status" => "ok", "result" => [] ]);
        
        $id_no = $tipo === "ig" ? $this->session->pg_loggato["id_personaggio"] : $this->session->email_giocatore;
        
        if( $tipo === "ig" )
            $query_dest = "SELECT id_personaggio AS real_value, CONCAT( nome_personaggio, ' (#', id_personaggio, ')' ) AS label FROM personaggi WHERE nome_personaggio LIKE :term AND id_personaggio != :idno AND contattabile_personaggio = 1 AND eliminato_personaggio = 0";
        else if( $tipo === "fg" )
            $query_dest = "SELECT email_giocatore AS real_value,
                                  CONCAT( nome_giocatore, ' ', cognome_giocatore ) AS label
                           FROM giocatori
                           WHERE CONCAT( nome_giocatore, ' ', cognome_giocatore ) LIKE :term AND email_giocatore != :idno AND eliminato_giocatore = 0";
        
        $ret["status"] = "ok";
        $ret["results"] = $this->db->doQuery( $query_dest, array( ":term" => "%$term%", ":idno" => $id_no ), False );
        
        return json_encode($ret);
    }
    
    public function recuperaDestinatariIG( $term )
    {
        return $this->recuperaDestinatari( "ig", $term );
    }
    
    public function recuperaDestinatariFG( $term )
    {
        return $this->recuperaDestinatari( "fg", $term );
    }
    
    public function recuperaNonLetti( )
    {
        UsersManager::controllaLogin( $this->session );
    
        $output = ["result" => []];
        
        $query_new_fg = "SELECT COUNT(id_messaggio) AS nuovi_fg FROM messaggi_fuorigioco WHERE letto_messaggio = 0 AND destinatario_messaggio = :mail";
        $valore_fg    = $this->db->doQuery($query_new_fg, [ ":mail" => $this->session->email_giocatore ], False);
        $output["result"]["fg"] = $valore_fg[0]["nuovi_fg"];
    
        if ( isset( $this->session->pg_loggato ) )
        {
            $query_new_ig = "SELECT COUNT(id_messaggio) AS nuovi_ig FROM messaggi_ingioco WHERE letto_messaggio = 0 AND destinatario_messaggio = :pgid";
            $valore_ig = $this->db->doQuery($query_new_ig, [":pgid" => $this->session->pg_loggato["id_personaggio"] ], False);
            $output["result"]["ig"] = $valore_ig[0]["nuovi_ig"];
        }
        
        $output["status"] = "ok";
        
        return json_encode($output);
    }
}