/*********************************************
 * OPL 12.8.0.0 Model
 * Author: abdelhalim_achergui
 * Creation Date: 19 mars 2019 at 10:32:20
 *********************************************/
int DEBUG = true;
string CSV_SEPARATOR = ";";
string COMMENT_SEPARATOR = "#";
// DATA Input
execute {

	function openFile (fileName,startLine) {  // startLine : numLine content data
		var file = new IloOplInputFile(fileName);
		if (! file.exists) {
		  writeln ("File "+fileName + " not exist ! \n");
		  fail();
        }
		var num = 1;
        while (num<startLine && !file.eof) {
           file.readline();
           num++;
        }
		if (file.eof) {
		  writeln ("File "+fileName + " : format error \n");
		  fail();
        }
        return file;
	}

    function getNbItemsFromFile (fileName,startLine) { // get nb items from title line
      var file = openFile(fileName,startLine);
      var title = file.readline().split(COMMENT_SEPARATOR)
      var nbItems = title[0].split(CSV_SEPARATOR).length;
      file.close();
      return nbItems;
    }

    function readItemsFromFile(f, nbItems) {
		var line = f.readline().split(CSV_SEPARATOR);
		if (line.length < nbItems ) {
		   writeln ("\n Nb items error -> ", line.length, ":",nbItems);
		   fail();
		}
        return line;
    }

}
// Program parameters
 int NB_NODE;      // 0..NB_NODE  = n
 int NB_SUPP = 10;    // 1..NB_SUPP
 string nodeFile = "C:/Users/Xfeltech/OneDrive/Documents/GitHub/PHPAuto/config/../data/bom_supemis_50.csv";
 string nodeSuppFile = "C:/Users/Xfeltech/OneDrive/Documents/GitHub/PHPAuto/config/../data/supp_list_50.csv";
 string suppDetailsFile = "C:/Users/Xfeltech/OneDrive/Documents/GitHub/PHPAuto/config/../data/supp_details_supeco_grdCapacity.csv";
 int service_t = 1;
 int EmisCap = 2500000;
 float EmisTax = 0;

 execute {
     // init NB_NODE NB_SUPP
     var nbItems = getNbItemsFromFile(nodeSuppFile,1);
     var prm = openFile(nodeSuppFile,2);
     var params = readItemsFromFile(prm,nbItems);
     NB_NODE = params[0];   
     prm.close();
 }

// Ranges
 range N = 0 .. NB_NODE;
 range M = 0 .. NB_NODE-1;
 range S = 1 .. NB_SUPP;
 
// BOM Data
 int num[N];
 int t_process[N];
 int parent[N];
 float unit_price[N];
 int rqtf[N];
 float aih_cost[N];
 float var_factor[N];
 float lt_factor[N];
 int cycle[N];
 int minOrder[N];
 
 int adup=20;
 int su[N][S];
 float sup[S][1..7]; //delay;price;capacity;emissions;quality;reliability;lead_time_variance
 int index_par[N];
 
 int facility_emis[N];
 float inventory_emis[N];
 int trsp_emis[N];
 int buff_trsp_coef=3;
 
 
execute {
//BOM Nodes Data
    cplex.tilim = 1800; // Time limit in seconds
 
     var nbItems = getNbItemsFromFile (nodeFile,1);
     var f = openFile(nodeFile,2);
     var nbNodes = 0;
     while (!f.eof && nbNodes<=NB_NODE){
         var data = readItemsFromFile(f,nbItems);
         nbNodes ++;
         if (DEBUG) {
           for (var i=0;i<data.length;i++) write(data[i]," ");
           writeln(data.length);
         }
             var ind = data[0];
             num[ind]= ind;
             t_process[ind] = data[1];
             parent[ind] = data[2];
             unit_price[ind] = data[3];
             rqtf[ind] = data[4];
             aih_cost[ind] = data[5];
             var_factor[ind] = data[6];
             lt_factor[ind] = data[7];
             cycle[ind] = data[8];
             minOrder[ind] = data[9];
             facility_emis[ind] = data[10];
             inventory_emis[ind] = data[11];
             trsp_emis[ind] = data[12];
     }
     f.close()
	 if (nbSupp<=NB_SUPP) {
		writeln (" Not enough nodes : ",nbNodes,"<",(NB_NODE+1));
		fail();
	 }
     writeln("Node nums:", num); 
     writeln("xx-Data uploaded-xx");
//Nodes-Supp Data
     writeln("\t**** start init su array ...");     
     var ff = openFile(nodeSuppFile,4);
     // init su array
     for (var i = 0; i<=NB_NODE;i++) 
       for (var j=1;j<=NB_SUPP;j++)
         su[i][j]=0;
     // format line :  indExp;sup1,sup2,sup3
     while (!ff.eof){
         var supp = ff.readline().split(";");
         if (supp.length>=2) { // contient au moins 2 elements
           var indNode = supp[0]; 
           var listSupp =supp[1].split(",");        
           for(var ss=0;ss<listSupp.length;ss++){
             var indSupp = 1*listSupp[ss];    // conversion string to int        
             if (indSupp>0 && indSupp <= NB_SUPP) {
                su[indNode][indSupp] = 1;
             }
           }
         }
     }
     ff.close();
     for (var ind =0;ind<=NB_NODE;ind++) writeln(ind,su[ind]);
     writeln("\t**** end init su array ...");

//Supp Data     
     nbItems = getNbItemsFromFile(suppDetailsFile,1);
     var fff = openFile(suppDetailsFile,2);
     var nbSupp=0;
     while (!fff.eof && nbSupp < NB_SUPP){
          var det = readItemsFromFile(fff,nbItems);
          if (DEBUG) {
           write(det.join(" "));
           writeln("("+det.length+")"+nbItems);
         }
         var index = det[0];
         sup[index][1] = det[1];
         sup[index][2] = det[2];
         sup[index][3] = det[3];
         sup[index][4] = det[4];
         sup[index][5] = det[5]; // quality_score
         sup[index][6] = det[6]; // reliability
         sup[index][7] = det[7]; // lead_time_variance
         nbSupp++;
     }
	 fff.close()
     if (nbSupp<NB_SUPP) {
       writeln (" Not enough suppliers : ",nbSupp,"<",NB_SUPP);
       fail();
     }
//Tree structure index
     for(var i in N){     
     	index_par[i]=0;
     	var j=0;
     	while((index_par[i]==0) && (j <= (NB_NODE))){
     	     	index_par[i]=1*(parent[j] == i);
     	     	j++;
     	}    
     }
 }//End execute

 //decision variables
 dvar boolean x[N]; //buffer ON/OFF
 dvar int a[N];	//dlt du noeud
 dvar int y[N]; //linearization var a*x 
 dvar boolean z[N][S]; //chosen supplier
 dvar int+ q[N][S]; //oder quantity per supplier
 
 //expressions
 dexpr float Emis_supp = sum (i in N)(sum (j in S) q[i][j]*sup[j][4]); //emissions des fournisseurs par produit englobant le transport et la  
 dexpr float Emis = Emis_supp + sum(i in N)(facility_emis[i]*x[i]+((inventory_emis[i]+((1/buff_trsp_coef)-1)*trsp_emis[i])*y[i]+trsp_emis[i]* a[i])*(1.5 + var_factor[i] ) * lt_factor[i] * rqtf[i] * adup );
 // Base procurement cost
dexpr float RawMCost = sum(i in N)( unit_price[i]*sum(j in S)(q[i][j]*sup[j][2]) ); // somme des achat selon fournisseur
// Quality and reliability penalty terms
dexpr float QualityPenalty = 0.1 * sum(i in N)( unit_price[i]*sum(j in S)(q[i][j]*(1.0 - sup[j][5])) );
dexpr float ReliabilityPenalty = 0.1 * sum(i in N)( unit_price[i]*sum(j in S)(q[i][j]*(1.0 - sup[j][6])) );
dexpr float RawMCostWithPenalties = RawMCost + QualityPenalty + ReliabilityPenalty;
 dexpr float InventCost = adup*sum(i in N)( aih_cost[i]*(1.5+var_factor[i])*lt_factor[i]*unit_price[i]*(1+sum(j in S)(z[i][j]*su[i][j]*sup[j][2]))*rqtf[i]*y[i] );
 dexpr float EmisCost = EmisTax * Emis;
 dexpr float TotalCostCS = RawMCostWithPenalties + InventCost;
 dexpr float TotalCostTS = EmisCost + TotalCostCS;
 //control expressions
 dexpr float dlts = sum(i in N)(a[i]);//sum of decoupled lead times
 dexpr float numSupp[i in N]= sum(j in S)(z[i][j]*su[i][j]);//total chosen suppliers
 
 //Ovjective function
 //minimize TotalCostCS+dlts;
 minimize TotalCostTS+dlts;
 //Constraintes
 subject to {
 	
 	forall (i in M){
		forall (j in (i+1)..NB_NODE){
			forall (k in S){
				a[i] >= t_process[i] + ((a[j] - y[j]) * (parent[j] == i)) + z[i][k]*su[i][k]*sup[k][1];
			}		
		}
	}
	forall (j in S){
		ct2: a[NB_NODE] >= t_process[NB_NODE]+ z[NB_NODE][j]*su[NB_NODE][j]*sup[j][1];	
	}
 	ct3: a[0]<=service_t;
 	forall (i in N){
 		ct4: y[i] <= a[i];
 		ct5: y[i] >= 0;
 		ct6: y[i] >= (a[i] - 1000*(1-x[i]));
 		ct7: y[i] <= 1000 * x[i];
 	}
 	forall (i in N){
 		ct8: numSupp[i]*(index_par[i]==0)>=(index_par[i]==0);
 		ct9: sum(j in S) q[i][j] == adup*rqtf[i]*(index_par[i]==0);
 	}
 	//ct10: Emis<=EmisCap;
	forall (i in N){
		forall (j in S){
			ct11: q[i][j] <= z[i][j]*sup[j][3];
			ct12: z[i][j]*(index_par[i]==1) == 0;
			//ct13: z[i][j]*(su[i][j]==0) <= su[i][j];
		}	
	}
 }
 tuple result {
 	float fctObj;
 	float StCosts;
 	float lts;
 	float emiss;
 }
 result Result;
 execute {
	writeln("xxxx");  // début du résultat pour php
 	Result.fctObj = cplex.getObjValue();
 	Result.StCosts = TotalCostTS;
	Result.lts = dlts;
	Result.emiss = Emis;
	write("#Result <fct_obj, tot_cst, tot_ldt, Emiss>:",Result);
	write("#TS:",TotalCostTS);
    write("#A:[");
    for (var i in N) {
	   write(a[i]);
       if(i<NB_NODE)write(",");
    }
    write("]");
    write("#X:[");
    for (var i in N) {
	   write(x[i]);
       if(i<NB_NODE)write(",");
    }
    write("]");
	write("#E: ",Emis);
	writeln("#DELIVER:");
	for (var i in N){
		for (var j in S){
			if (z[i][j]!=0) {
				writeln("S",j,"=>P",i);
			}
		}	
	}
	writeln("xxxx");  // fin du résultat pour php
 }