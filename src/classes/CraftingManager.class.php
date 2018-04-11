<?php

$path = $_SERVER['DOCUMENT_ROOT']."/reboot-live-api/src/";
include_once($path."classes/APIException.class.php");
include_once($path."classes/UsersManager.class.php");
include_once($path."classes/DatabaseBridge.class.php");
include_once($path."classes/SessionManager.class.php");
include_once($path."classes/Utils.class.php");
include_once($path."config/constants.php");

class CraftingManager
{
    protected $idev_in_corso;
    protected $session;
    protected $db;
    
    public function __construct( $idev_in_corso = NULL )
    {
        $this->idev_in_corso = $idev_in_corso;
        $this->db = new DatabaseBridge();
        $this->session = SessionManager::getInstance();
    }
    
    public function __destruct()
    {
    }
    
    public function inserisciRicettaNetrunner( $pgid, $programmi )
    {
        global $GRANT_VISUALIZZA_CRAFT_PROGRAM;
        
        UsersManager::operazionePossibile( $this->session, $GRANT_VISUALIZZA_CRAFT_PROGRAM );
        
        $nome_programma = $programmi[0]["nome_programma"];
        $valori_usati   = [];
        $risultati      = [];
        unset($programmi[0]["nome_programma"]);
    
        foreach ($programmi as $p)
        {
            $sql_x = "SELECT effetto_valore_crafting AS effetto, parametro_collegato_crafting AS pcc FROM crafting_programmazione WHERE parametro_crafting = 'X1' AND valore_parametro_crafting = :x_val";
            $res_x = $this->db->doQuery($sql_x, [":x_val" => $p["x_val"]], False);
            
            $sql_y = "SELECT effetto_valore_crafting AS effetto, parametro_collegato_crafting AS pcc FROM crafting_programmazione WHERE parametro_crafting = :pcc AND valore_parametro_crafting = :y_val";
            $res_y = $this->db->doQuery($sql_y, [":pcc" => $res_x[0]["pcc"], ":y_val" => $p["y_val"]], False);
            
            $sql_z = "SELECT effetto_valore_crafting AS effetto FROM crafting_programmazione WHERE parametro_crafting = :pcc AND valore_parametro_crafting = :z_val";
            $res_z = $this->db->doQuery($sql_z, [":pcc" => $res_y[0]["pcc"], ":z_val" => $p["z_val"]], False);
    
            $valori_usati[] = "X=".$p["x_val"]."; Y=".$p["y_val"]."; Z=".$p["z_val"];
            $risultati[] = $res_x[0]["effetto"]." - ".$res_y[0]["effetto"]." - ".$res_z[0]["effetto"];
        }
        
        $params = [
            ":idpg"     => $pgid,
            ":tipo"     => "Programmazione",
            ":tipo_ogg" => "Applicativo",
            ":nome"     => $nome_programma,
            ":comps"    => implode("@@", $valori_usati),
            ":res"      => implode("@@", $risultati)
        ];
        
        $sql_ricetta = "INSERT INTO ricette VALUES (NULL, :idpg, NOW(), :tipo, :tipo_ogg, :nome, :comps, :res, 0, NULL, NULL )";
        $this->db->doQuery( $sql_ricetta, $params, False );
        
        $output = ["status" => "ok", "result"=> true];
        
        return json_encode($output);
    }
    
    public function inserisciRicettaTecnico( $pgid, $batteria, $struttura, $applicativo )
    {
        global $GRANT_VISUALIZZA_CRAFT_TECNICO;
    
        UsersManager::operazionePossibile( $this->session, $GRANT_VISUALIZZA_CRAFT_TECNICO );
    
    }
    
    public function inserisciRicettaMedico( $pgid, $substrato, $principio_att, $psicotropo )
    {
        global $GRANT_VISUALIZZA_CRAFT_CHIMICO;
    
        UsersManager::operazionePossibile( $this->session, $GRANT_VISUALIZZA_CRAFT_CHIMICO );
    
    }
    
    public function modificaRicetta( $id_r, $modifiche )
    {
        UsersManager::operazionePossibile( $this->session, __FUNCTION__ );
        
        $to_update = implode(" = ?, ",array_keys($modifiche) )." = ?";
        $valori = array_values($modifiche);
        $valori[] = $id_r;
    
        $query = "UPDATE ricette SET $to_update WHERE id_ricetta = ?";
    
        $this->db->doQuery( $query, $valori, False );
    
        return "{\"status\": \"ok\",\"result\": \"true\"}";
    }
    
    public function recuperaRicette( $draw, $columns, $order, $start, $length, $search, $pgid = -1 )
    {
        UsersManager::operazionePossibile( $this->session, __FUNCTION__, $pgid );
        
        $filter     = False;
        $where      = "";
        $params     = [];
    
        if( isset( $search ) && $search["value"] != "" )
        {
            $filter = True;
            $params[":search"] = "%$search[value]%";
            $where .= " (
						r.nome_giocatore LIKE :search OR
						r.personaggi_id_personaggio LIKE :search OR
						r.nome_personaggio LIKE :search OR
						r.tipo_ricetta LIKE :search OR
						r.componenti_ricetta LIKE :search OR
						r.risultato_ricetta LIKE :search OR
						r.note_ricetta LIKE :search OR
						r.extra_cartellino_ricetta LIKE :search OR
						r.data_inserimento_it LIKE :search
					  )";
        }
    
        if( isset( $order ) )
        {
            $sorting = array();
            foreach ( $order as $elem )
                $sorting[] = "r.".$columns[$elem["column"]]["data"]." ".$elem["dir"];
        
            $order_str = "ORDER BY ".implode( $sorting, "," );
        }
    
        if( !empty($where) )
            $where = "WHERE".$where;
    
        $query_ric = "SELECT * FROM
                      (
                        SELECT  ri.id_ricetta,
                                ri.personaggi_id_personaggio,
                                DATE_FORMAT( ri.data_inserimento_ricetta, '%d/%m/%Y %H:%i:%s' ) as data_inserimento_it,
                                ri.data_inserimento_ricetta,
                                ri.tipo_ricetta,
                                ri.tipo_oggetto,
                                ri.nome_ricetta,
                                ri.componenti_ricetta,
                                ri.risultato_ricetta,
                                ri.approvata_ricetta,
                                ri.note_ricetta,
                                ri.extra_cartellino_ricetta,
                                CONCAT(gi.nome_giocatore,' ',gi.cognome_giocatore) AS nome_giocatore,
                                pg.nome_personaggio
                        FROM ricette AS ri
                            JOIN personaggi AS pg ON pg.id_personaggio = ri.personaggi_id_personaggio
                            JOIN giocatori AS gi ON gi.email_giocatore = pg.giocatori_email_giocatore
                      ) AS r $where $order_str";
    
        $risultati  = $this->db->doQuery( $query_ric, $params, False );
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
    
        return json_encode($output);
    }
    
    public function recupeRaricetteConId( $ids )
    {
        UsersManager::operazionePossibile( $this->session, "recuperaRicette", -1 );
        
        $marcatori = str_repeat( "?, ", count($ids) - 1 )."?";
        $query_ric = "SELECT * FROM ricette WHERE id_ricetta IN ($marcatori)";
        $risultati  = $this->db->doQuery( $query_ric, $ids, False );
        
        $output = [
            "status" => "ok",
            "result" => $risultati
        ];
    
        return json_encode($output);
    }
}