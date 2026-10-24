<?php
// Synthetic values with captured GetExtendInfo and Check v2 paths and formats.
declare(strict_types=1);
return [
    'RenewalInfo' => <<<'XML'
<interface-response>
<registrarhold>False</registrarhold>
<expiration>10/22/2027</expiration>
<maxextension>9</maxextension>
<minallowed>1</minallowed>
<ccauthorized>synthetic</ccauthorized>
<price>8.95</price>
<balance>12,345.67</balance>
<availablebalance>12,345.67</availablebalance>
<nazca>False</nazca>
<customerprefs><defperiod>1</defperiod><allowdns>False</allowdns><showpopups>False</showpopups><autorenew>True</autorenew><reglock>True</reglock><autopakrenew>True</autopakrenew><usedns>True</usedns><resellerstatus /><renewalsetting /><renewalbcc /><renewalurlforward>True</renewalurlforward><renewalemailforward>synthetic</renewalemailforward><mailnumlimit>100</mailnumlimit><idprotect>False</idprotect><defidprotectrenew>True</defidprotectrenew><defwblrenew>True</defwblrenew><namejetsales>False</namejetsales><irtpoptout /><irtpemaillanguagecode>synthetic</irtpemaillanguagecode><defaulthostrecords><hostrecord hostname="*" address="192.0.2.1" recordtype="A" /><hostrecord hostname="WWW" address="192.0.2.1" recordtype="A" /><hostrecord hostname="@" address="192.0.2.1" recordtype="A" /><hostrecord hostname="" address="192.0.2.1" recordtype="A" /><hostrecord hostname="" address="192.0.2.1" recordtype="A" /></defaulthostrecords><defaulthostrecordown>False</defaulthostrecordown><useourdns>True</useourdns><nameservers><dns1>dns1.name-services.com</dns1><dns2>dns2.name-services.com</dns2><dns3>dns3.name-services.com</dns3><dns4>dns4.name-services.com</dns4><dns5>dns5.name-services.com</dns5></nameservers></customerprefs>
<command>GetExtendInfo</command>
<errcount>0</errcount>
<done>true</done>
</interface-response>
XML,
    'Check' => <<<'XML'
<interface-response>
<domains><domain><name>example.com</name><rrpcode>211</rrpcode><rrptext>Domain not available</rrptext><ispremium>False</ispremium><isplatinum>False</isplatinum><iseap>False</iseap><prices><currency /><registration>8.95</registration><renewal>8.95</renewal><restore>160.00</restore><transfer>9.50</transfer><expectedcustomersuppliedprice /></prices><properties><nativesld /><minregyear>1</minregyear><maxregyear>10</maxregyear><abletolock>True</abletolock><extattributes>False</extattributes><transferable>True</transferable><allowwpps>True</allowwpps><trademarkstart utc="" epoch="" /><trademarkend utc="" epoch="" /></properties><eap /></domain></domains>
<command>CHECK</command>
<apitype>API.NET</apitype>
<language>eng</language>
<errcount>0</errcount>
<responsecount>0</responsecount>
<minperiod>1</minperiod>
<maxperiod>10</maxperiod>
<server />
<site>eNom</site>
<islockable />
<isrealtimetld />
<timedifference>+0.00</timedifference>
<exectime>0.172</exectime>
<done>true</done>
<trackingkey>synthetic</trackingkey>
<requestdatetime>10/22/2026 4:33:08 AM</requestdatetime>
<debug />
</interface-response>
XML,
];
