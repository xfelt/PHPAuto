/*********************************************
 * Multi-Objective Model - Pseudo-Linear Model (PLM)
 * Objectives: Cost, DIO (Days Inventory Outstanding), WIP (Work In Process), Emissions
 * Method: Epsilon-Constraint for Pareto Front Generation
 * Author: Generated for KPI-oriented scenarios
 * Creation Date: 2025
 *********************************************/
int DEBUG = true;
string CSV_SEPARATOR = ";";
string COMMENT_SEPARATOR = "#";

// Multi-objective parameters (epsilon constraints)
float epsilon_DIO = 10000000000;      // Constraint on DIO (sum of decoupled lead times)
float epsilon_WIP = 10000000000;       // Constraint on WIP (inventory value)
float epsilon_Emis = 10000000000;     // Constraint on Emissions
int obj_primary = 3;         // Primary objective: 1=Cost, 2=DIO, 3=WIP, 4=Emissions

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
 string nodeFile = "bom_supemis_26.csv";
 string nodeSuppFile = "supp_list_26.csv";
 string suppDetailsFile = "supp_details_supeco_grdCapacity.csv";
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
float sup[S][1..4]; //delay;price;capacity;emissions
int index_par[N];
 
int facility_emis[N];
float inventory_emis[N];
int trsp_emis[N];
int buff_trsp_coef=3;
 
 
execute {
//BOM Nodes Data 
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
	 if (nbNodes<=NB_NODE) {
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
 dvar int a[N];	//dlt du noeud (decoupled lead time)
 dvar int y[N]; //linearization var a*x 
 dvar boolean z[N][S]; //chosen supplier
 dvar int+ q[N][S]; //order quantity per supplier
 
 //expressions - Objective components
 dexpr float Emis_supp = sum (i in N)(sum (j in S) q[i][j]*sup[j][4]); //emissions from suppliers
 dexpr float Emis = Emis_supp + sum(i in N)(facility_emis[i]*x[i]+((inventory_emis[i]+((1/buff_trsp_coef)-1)*trsp_emis[i])*y[i]+trsp_emis[i]* a[i])*(1.5 + var_factor[i] ) * lt_factor[i] * rqtf[i] * adup );
 dexpr float RawMCost = sum(i in N)( unit_price[i]*sum(j in S)(q[i][j]*sup[j][2]) ); // raw material cost
 dexpr float InventCost = adup*sum(i in N)( aih_cost[i]*(1.5+var_factor[i])*lt_factor[i]*unit_price[i]*(1+sum(j in S)(z[i][j]*su[i][j]*sup[j][2]))*rqtf[i]*y[i] );
 dexpr float EmisCost = EmisTax * Emis;
 dexpr float TotalCostCS = RawMCost + InventCost;
 dexpr float TotalCostTS = EmisCost + TotalCostCS;
 
 // Multi-objective expressions
 dexpr float DIO = sum(i in N)(a[i]);  // Days Inventory Outstanding (sum of decoupled lead times)
 dexpr float WIP = sum(i in N)(unit_price[i]*(1+sum(j in S)(z[i][j]*su[i][j]*sup[j][2]))*rqtf[i]*y[i]*adup);  // Work In Process (inventory value)
 
 //control expressions
 dexpr float numSupp[i in N]= sum(j in S)(z[i][j]*su[i][j]);//total chosen suppliers
 
 //Primary objective selection
 dexpr float PrimaryObj = 
     (obj_primary == 1) ? TotalCostCS :
     (obj_primary == 2) ? DIO :
     (obj_primary == 3) ? WIP :
     (obj_primary == 4) ? Emis :
     TotalCostCS;  // default to cost
 
 //Objective function - minimize primary objective
 minimize PrimaryObj;
 
 //Constraints
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
 		ct10: sum(j in S) q[i][j] == adup*rqtf[i]*(index_par[i]==0);
 	}
 	ct9: Emis<=EmisCap;
 	
 	// Epsilon constraints for Pareto front generation
 	ct_epsilon_DIO: DIO <= epsilon_DIO;      // Constraint on DIO
 	ct_epsilon_WIP: WIP <= epsilon_WIP;       // Constraint on WIP
 	ct_epsilon_Emis: Emis <= epsilon_Emis;     // Constraint on Emissions
 	
	forall (i in N){
		forall (j in S){
			ct11: q[i][j] <= z[i][j]*sup[j][3];
			ct12: z[i][j]*(index_par[i]==1) == 0;
		}	
	}
 }
 
 tuple result {
 	float fctObj;
 	float TotalCost;
 	float DIO;
 	float WIP;
 	float Emiss;
 	float RawMCost;
 	float InventCost;
 	float EmisCost;
 }
 result Result;
 execute {
	writeln("xxxx");  // début du résultat pour php
 	Result.fctObj = cplex.getObjValue();
 	Result.TotalCost = TotalCostCS;
 	Result.DIO = DIO;
 	Result.WIP = WIP;
 	Result.Emiss = Emis;
 	Result.RawMCost = RawMCost;
 	Result.InventCost = InventCost;
 	Result.EmisCost = EmisCost;
 	write("#Result <fct_obj, tot_cost, DIO, WIP, Emiss>:",Result);
 	write("#CS:",TotalCostCS);
 	write("#DIO:",DIO);
 	write("#WIP:",WIP);
 	write("#E: ",Emis);
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
