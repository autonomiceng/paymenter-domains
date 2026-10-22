<?php
// Synthetic values preserving captured Enom read paths, nesting and scalar formats.
declare(strict_types=1);
return [
    'Expiry' => <<<'XML'
<interface-response>
<expirationdate>10/22/2027 6:53:00 AM</expirationdate>
<command>GETDOMAINEXP</command>
<apitype>API.NET</apitype>
<language>eng</language>
<errcount>0</errcount>
<responsecount>0</responsecount>
<minperiod>1</minperiod>
<maxperiod>10</maxperiod>
<server />
<site>eNom</site>
<islockable>True</islockable>
<isrealtimetld>True</isrealtimetld>
<timedifference>+8.00</timedifference>
<exectime>0.172</exectime>
<done>true</done>
<trackingkey>synthetic</trackingkey>
<requestdatetime>10/22/2026 4:36:41 AM</requestdatetime>
<debug />
</interface-response>
XML,
    'Contacts' => <<<'XML'
<interface-response>
<getcontacts><domainname sld="example" tld="com" domainnameid="456">example.com</domainname>
<registrant><registrantpartyid /><registrantorganizationname>synthetic</registrantorganizationname><registrantjobtitle>synthetic</registrantjobtitle><registrantfirstname>Alex</registrantfirstname><registrantlastname>Example</registrantlastname><registrantaddress1>123 Example Street</registrantaddress1><registrantaddress2>123 Example Street</registrantaddress2><registrantcity>synthetic</registrantcity><registrantcountry>US</registrantcountry><registrantstateprovince>synthetic</registrantstateprovince><registrantstateprovincechoice>synthetic</registrantstateprovincechoice><registrantpostalcode>12345</registrantpostalcode><registrantemailaddress>person@example.com</registrantemailaddress><registrantphone>+1.2025550100</registrantphone><registrantphoneext>+1.2025550100</registrantphoneext><registrantfax>+1.2025550100</registrantfax><registrantconsentstatus>PENDING</registrantconsentstatus><registrantconsentstatusdisplay>Pending - Synchronous</registrantconsentstatusdisplay><registrantcontactmatch>UseCustom</registrantcontactmatch></registrant>
<admin><adminpartyid /><adminorganizationname>synthetic</adminorganizationname><adminjobtitle>synthetic</adminjobtitle><adminfirstname>Alex</adminfirstname><adminlastname>Example</adminlastname><adminaddress1>123 Example Street</adminaddress1><adminaddress2>123 Example Street</adminaddress2><admincity>synthetic</admincity><admincountry>US</admincountry><adminstateprovince>synthetic</adminstateprovince><adminstateprovincechoice>synthetic</adminstateprovincechoice><adminpostalcode>12345</adminpostalcode><adminemailaddress>person@example.com</adminemailaddress><adminphone>+1.2025550100</adminphone><adminphoneext>+1.2025550100</adminphoneext><adminfax>+1.2025550100</adminfax><admincontactmatch>NotCollected</admincontactmatch></admin>
<tech><techpartyid /><techorganizationname>synthetic</techorganizationname><techjobtitle>synthetic</techjobtitle><techfirstname>Alex</techfirstname><techlastname>Example</techlastname><techaddress1>123 Example Street</techaddress1><techaddress2>123 Example Street</techaddress2><techcity>synthetic</techcity><techcountry>US</techcountry><techstateprovince>synthetic</techstateprovince><techstateprovincechoice>synthetic</techstateprovincechoice><techpostalcode>12345</techpostalcode><techemailaddress>person@example.com</techemailaddress><techphone>+1.2025550100</techphone><techphoneext>+1.2025550100</techphoneext><techfax>+1.2025550100</techfax><techcontactmatch>NotCollected</techcontactmatch></tech>
<billing><billingpartyid /><billingorganizationname>synthetic</billingorganizationname><billingjobtitle>synthetic</billingjobtitle><billingfirstname>Alex</billingfirstname><billinglastname>Example</billinglastname><billingaddress1>123 Example Street</billingaddress1><billingaddress2>123 Example Street</billingaddress2><billingcity>synthetic</billingcity><billingcountry>US</billingcountry><billingstateprovince>synthetic</billingstateprovince><billingstateprovincechoice>synthetic</billingstateprovincechoice><billingpostalcode>12345</billingpostalcode><billingemailaddress>person@example.com</billingemailaddress><billingphone>+1.2025550100</billingphone><billingphoneext>+1.2025550100</billingphoneext><billingfax>+1.2025550100</billingfax><billingcontactmatch /></billing>
<auxbilling><auxbillingpartyid /><auxbillingorganizationname>synthetic</auxbillingorganizationname><auxbillingjobtitle>synthetic</auxbillingjobtitle><auxbillingfirstname>Alex</auxbillingfirstname><auxbillinglastname>Example</auxbillinglastname><auxbillingaddress1>123 Example Street</auxbillingaddress1><auxbillingaddress2>123 Example Street</auxbillingaddress2><auxbillingcity>synthetic</auxbillingcity><auxbillingcountry>US</auxbillingcountry><auxbillingstateprovince>synthetic</auxbillingstateprovince><auxbillingstateprovincechoice>synthetic</auxbillingstateprovincechoice><auxbillingpostalcode>12345</auxbillingpostalcode><auxbillingemailaddress>person@example.com</auxbillingemailaddress><auxbillingphone>+1.2025550100</auxbillingphone><auxbillingphoneext>+1.2025550100</auxbillingphoneext><auxbillingfax>+1.2025550100</auxbillingfax><auxid /><regid /><auxbillingcontactmatch>NotCollected</auxbillingcontactmatch></auxbilling>
<icanncompliant>True</icanncompliant><pendingverification>False</pendingverification><raa_status>False</raa_status><raa_statusdesc /><irtpoptout /><transferlock>False</transferlock><transferlockexpdate daysremaining="" epoch="" utc="" /><nexus category="" /><purpose /><currentattributes /><wppsallowed>1</wppsallowed><wppsexists>0</wppsexists><wppsenabled>0</wppsenabled><wppsexpdate /><wppsautorenew /><wppscontactdata><contacttype>WPPS</contacttype><organizationname>synthetic</organizationname><jobtitle>synthetic</jobtitle><fname /><lname /><address1>123 Example Street</address1><address2>123 Example Street</address2><city>synthetic</city><stateprovince>synthetic</stateprovince><stateprovincechoice>synthetic</stateprovincechoice><postalcode>12345</postalcode><country>US</country><phone>+1.2025550100</phone><phoneext>+1.2025550100</phoneext><fax>+1.2025550100</fax><emailaddress>person@example.com</emailaddress><datetimechanged /><contactmatch /></wppscontactdata>
<whoispublicity><vasitemid>synthetic</vasitemid><prodstatusid /><prodstatusdesc /><prodenabled>synthetic</prodenabled><prodconsented>synthetic</prodconsented><expdate epoch="1824213180" utc="2027-10-22T13:53:00Z" /><autorenew>synthetic</autorenew></whoispublicity>
<escrowliftdate /><escrowhold /><attributes /><iscontactshared>False</iscontactshared></getcontacts>
<contactrestrictedtld>False</contactrestrictedtld>
<command>GETCONTACTS</command>
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
<exectime>1.860</exectime>
<done>true</done>
<trackingkey>synthetic</trackingkey>
<requestdatetime>10/22/2026 4:36:43 AM</requestdatetime>
<debug />
</interface-response>
XML,
    'Lock' => <<<'XML'
<interface-response>
<reg-lock>1</reg-lock>
<registrar>eNom</registrar>
<rrpcode>200</rrpcode>
<rrptext>Command completed successfully</rrptext>
<command>GETREGLOCK</command>
<apitype>API.NET</apitype>
<language>eng</language>
<errcount>0</errcount>
<responsecount>0</responsecount>
<minperiod>1</minperiod>
<maxperiod>10</maxperiod>
<server />
<site>eNom</site>
<islockable>True</islockable>
<isrealtimetld>True</isrealtimetld>
<timedifference>+8.00</timedifference>
<exectime>0.562</exectime>
<done>true</done>
<trackingkey>synthetic</trackingkey>
<requestdatetime>10/22/2026 4:36:44 AM</requestdatetime>
<debug />
</interface-response>
XML,
    'Privacy' => <<<'XML'
<interface-response>
<getwppsinfo><domainname sld="example" tld="com" domainnameid="456">example.com</domainname>
<wppsallowed>1</wppsallowed><wppsexists>0</wppsexists><wppsenabled>0</wppsenabled><wppsexpdate /><wppsautorenew /><wppsprice>$6.00</wppsprice><contacts><contact contacttype="Administrative"><organization>synthetic</organization><fname /><lname /><address1>123 Example Street</address1><address2>123 Example Street</address2><city>synthetic</city><stateprovince>synthetic</stateprovince><postalcode>12345</postalcode><country>US</country><phone>+1.2025550100</phone><fax>+1.2025550100</fax><emailaddress>person@example.com</emailaddress></contact>
<contact contacttype="Aux Billing"><organization>synthetic</organization><fname /><lname /><address1>123 Example Street</address1><address2>123 Example Street</address2><city>synthetic</city><stateprovince>synthetic</stateprovince><postalcode>12345</postalcode><country>US</country><phone>+1.2025550100</phone><fax>+1.2025550100</fax><emailaddress>person@example.com</emailaddress></contact>
<contact contacttype="Billing"><organization>synthetic</organization><fname /><lname /><address1>123 Example Street</address1><address2>123 Example Street</address2><city>synthetic</city><stateprovince>synthetic</stateprovince><postalcode>12345</postalcode><country>US</country><phone>+1.2025550100</phone><fax>+1.2025550100</fax><emailaddress>person@example.com</emailaddress></contact>
<contact contacttype="Registrant"><organization>synthetic</organization><fname /><lname /><address1>123 Example Street</address1><address2>123 Example Street</address2><city>synthetic</city><stateprovince>synthetic</stateprovince><postalcode>12345</postalcode><country>US</country><phone>+1.2025550100</phone><fax>+1.2025550100</fax><emailaddress>person@example.com</emailaddress></contact>
<contact contacttype="Technical"><organization>synthetic</organization><fname /><lname /><address1>123 Example Street</address1><address2>123 Example Street</address2><city>synthetic</city><stateprovince>synthetic</stateprovince><postalcode>12345</postalcode><country>US</country><phone>+1.2025550100</phone><fax>+1.2025550100</fax><emailaddress>person@example.com</emailaddress></contact>
</contacts></getwppsinfo>
<command>GETWPPSINFO</command>
<apitype>API</apitype>
<language>eng</language>
<errcount>0</errcount>
<responsecount>0</responsecount>
<minperiod>1</minperiod>
<maxperiod>10</maxperiod>
<server />
<site>eNom</site>
<islockable>True</islockable>
<isrealtimetld>True</isrealtimetld>
<timedifference>+08.00</timedifference>
<exectime>0.000</exectime>
<done>true</done>
<trackingkey>synthetic</trackingkey>
<requestdatetime>10/22/2026 4:36:45 AM</requestdatetime>
<debug />
</interface-response>
XML,
    'Renewal' => <<<'XML'
<interface-response>
<auto-renew>0</auto-renew>
<autopakrenew>0</autopakrenew>
<pakexist>0</pakexist>
<emailforwardrenew>person@example.com</emailforwardrenew>
<emailfwdexists>person@example.com</emailfwdexists>
<urlforwardrenew>0</urlforwardrenew>
<urlfwdexists>0</urlfwdexists>
<idprotectrenew>0</idprotectrenew>
<idprotectexists>0</idprotectexists>
<mobilizerrenew>0</mobilizerrenew>
<command>GETRENEW</command>
<apitype>API.NET</apitype>
<language>eng</language>
<errcount>0</errcount>
<responsecount>0</responsecount>
<minperiod>1</minperiod>
<maxperiod>10</maxperiod>
<server />
<site>eNom</site>
<islockable>True</islockable>
<isrealtimetld>True</isrealtimetld>
<timedifference>+8.00</timedifference>
<exectime>0.172</exectime>
<done>true</done>
<trackingkey>synthetic</trackingkey>
<requestdatetime>10/22/2026 4:36:46 AM</requestdatetime>
<debug />
</interface-response>
XML,
    'Nameservers' => <<<'XML'
<interface-response>
<dns>dns1.name-services.com</dns>
<dns>dns2.name-services.com</dns>
<dns>dns3.name-services.com</dns>
<dns>dns4.name-services.com</dns>
<dns>dns5.name-services.com</dns>
<usedns>default</usedns>
<nsstatus>Yes</nsstatus>
<hostsnumlimit>15</hostsnumlimit>
<nameserverregistrysynced>True</nameserverregistrysynced>
<rrpcodegdns>200</rrpcodegdns>
<rrptext>Command completed successfully</rrptext>
<command>GETDNS</command>
<apitype>API</apitype>
<language>eng</language>
<errcount>0</errcount>
<responsecount>0</responsecount>
<minperiod>1</minperiod>
<maxperiod>10</maxperiod>
<server />
<site>eNom</site>
<islockable>True</islockable>
<isrealtimetld>True</isrealtimetld>
<timedifference>+08.00</timedifference>
<exectime>0.000</exectime>
<done>true</done>
<trackingkey>synthetic</trackingkey>
<requestdatetime>10/22/2026 4:36:47 AM</requestdatetime>
<debug />
</interface-response>
XML,
    'Hosts' => <<<'XML'
<interface-response>
<host><name>*</name><type>A</type><address>192.0.2.1</address><hostid>12345678</hostid></host>
<domainservices><emailforwarding>person@example.com</emailforwarding><hostrecords>True</hostrecords></domainservices>
<domainlimits><hostsrecordlimit>15</hostsrecordlimit><mailforwardlimit>100</mailforwardlimit></domainlimits>
<hostrecordcount>1</hostrecordcount>
<mxrecordcount>5</mxrecordcount>
<command>GETHOSTS</command>
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
<exectime>0.235</exectime>
<done>true</done>
<trackingkey>synthetic</trackingkey>
<requestdatetime>10/22/2026 4:36:48 AM</requestdatetime>
<debug />
</interface-response>
XML,
    'Srv' => <<<'XML'
<interface-response>
<srv-records />
<command>GETDOMAINSRVHOSTS</command>
<apitype>API</apitype>
<language>eng</language>
<errcount>0</errcount>
<responsecount>0</responsecount>
<minperiod>1</minperiod>
<maxperiod>10</maxperiod>
<server />
<site>eNom</site>
<islockable>True</islockable>
<isrealtimetld>True</isrealtimetld>
<timedifference>+08.00</timedifference>
<exectime>0.000</exectime>
<done>true</done>
<trackingkey>synthetic</trackingkey>
<requestdatetime>10/22/2026 4:36:49 AM</requestdatetime>
<debug />
</interface-response>
XML,
    'Ds' => <<<'XML'
<interface-response>
<dnssecdata><result><responsecode>200</responsecode><responsemessage>Command completed successfully</responsemessage></result></dnssecdata>
<maxsiglife />
<success>True</success>
<dnssecdatacount>0</dnssecdatacount>
<command>GETDNSSEC</command>
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
<exectime>0.406</exectime>
<done>true</done>
<trackingkey>synthetic</trackingkey>
<requestdatetime>10/22/2026 4:36:50 AM</requestdatetime>
<debug />
</interface-response>
XML,
    'Balance' => <<<'XML'
<interface-response>
<reseller>1</reseller>
<balance>12,300.00</balance>
<availablebalance>12,345.67</availablebalance>
<price>8.95</price>
<comprice>8.95</comprice>
<netprice>15.55</netprice>
<orgprice>synthetic</orgprice>
<ccprice>24.95</ccprice>
<tvprice>39.95</tvprice>
<bzprice>24.95</bzprice>
<nuprice>24.95</nuprice>
<domaincount>17</domaincount>
<command>GETBALANCE</command>
<apitype>API</apitype>
<language>eng</language>
<errcount>0</errcount>
<responsecount>0</responsecount>
<minperiod>1</minperiod>
<maxperiod>10</maxperiod>
<server />
<site>eNom</site>
<islockable>False</islockable>
<isrealtimetld>True</isrealtimetld>
<timedifference>+03.00</timedifference>
<exectime>0.000</exectime>
<done>true</done>
<trackingkey>synthetic</trackingkey>
<requestdatetime>10/22/2026 4:36:51 AM</requestdatetime>
<debug />
</interface-response>
XML,
    'Expired' => <<<'XML'
<interface-response>
<nodomainnames>True</nodomainnames>
<domaincount>0</domaincount>
<command>GETEXPIREDDOMAINS</command>
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
<exectime>0.094</exectime>
<done>true</done>
<trackingkey>synthetic</trackingkey>
<requestdatetime>10/22/2026 4:36:52 AM</requestdatetime>
<debug />
</interface-response>
XML,
    'ProductPrice' => <<<'XML'
<interface-response>
<productprice><price>8.95</price><productenabled>True</productenabled></productprice>
<command>PE_GETPRODUCTPRICE</command>
<apitype>API</apitype>
<language>eng</language>
<errcount>0</errcount>
<responsecount>0</responsecount>
<minperiod>1</minperiod>
<maxperiod>10</maxperiod>
<server />
<site>eNom</site>
<islockable>True</islockable>
<isrealtimetld>True</isrealtimetld>
<timedifference>+08.00</timedifference>
<exectime>0.000</exectime>
<done>true</done>
<trackingkey>synthetic</trackingkey>
<requestdatetime>10/22/2026 4:36:53 AM</requestdatetime>
<debug />
</interface-response>
XML,
    'TldInfo' => <<<'XML'
<interface-response>
<tlds><tld><tldid>0</tldid><tld>com</tld><abletolock>True</abletolock><allowwpps>True</allowwpps><autorenewonly>False</autorenewonly><extattributes>False</extattributes><allowwbl>True</allowwbl><transrequiresnewcontact>False</transrequiresnewcontact><encodingtype>PUNY</encodingtype><langcode /><nativedisplay /><clearphonenumber>+1.2025550100</clearphonenumber><clearfax>+1.2025550100</clearfax><validatednshosting>True</validatednshosting><haspremiumnames>False</haspremiumnames><supportsdnssec>True</supportsdnssec><allowwhoispublicity>synthetic</allowwhoispublicity><rdpenabled>True</rdpenabled><registration><realtime>True</realtime><unit>Year</unit><minimum>1</minimum><maximum>10</maximum><haspremiumnames>False</haspremiumnames><extattributes>False</extattributes><extattributesdomainlevel>False</extattributesdomainlevel><dnsrequired>False</dnsrequired><dnsminimum>0</dnsminimum><dnsmaximum>13</dnsmaximum><generalavailabilitystartdate>2000-01-01T00:00:00</generalavailabilitystartdate></registration>
<renewal><autorenewonly>False</autorenewonly><autorenewed>True</autorenewed><renewbeforeexpmonths>0</renewbeforeexpmonths><deletetype>0</deletetype><deleteday>42</deleteday><graceperiod>45</graceperiod><reactivate>True</reactivate><restorable>True</restorable><rgp>True</rgp><rgpdays>32</rgpdays><extendedrgp>True</extendedrgp><transferperiod>0</transferperiod></renewal>
<transfer><transferable>True</transferable><authinfo>synthetic</authinfo><realtime>False</realtime><autoverification>True</autoverification><autofax>+1.2025550100</autofax><transferbyfoa>False</transferbyfoa><requiresnewcontact>False</requiresnewcontact></transfer>
<premiumnames><haspremiumnames>False</haspremiumnames><maxpremiumregyears>0</maxpremiumregyears><trademarkstartdate>1900-01-01T00:00:00</trademarkstartdate><trademarkenddate>1900-01-01T00:00:00</trademarkenddate></premiumnames>
<nameservers><requiresdns>False</requiresdns><minnameservers>0</minnameservers><maxnameservers>13</maxnameservers></nameservers>
<rdprules><rdprule><contacttype>registrant</contacttype><contactfield>Address</contactfield><collectiontype>required</collectiontype><transmittoregistry>False</transmittoregistry></rdprule><rdprule><contacttype>registrant</contacttype><contactfield>Address 2</contactfield><collectiontype>optional</collectiontype><transmittoregistry>False</transmittoregistry></rdprule><rdprule><contacttype>registrant</contacttype><contactfield>Address 3</contactfield><collectiontype>optional</collectiontype><transmittoregistry>False</transmittoregistry></rdprule><rdprule><contacttype>registrant</contacttype><contactfield>City</contactfield><collectiontype>required</collectiontype><transmittoregistry>False</transmittoregistry></rdprule><rdprule><contacttype>registrant</contacttype><contactfield>Country Code</contactfield><collectiontype>required</collectiontype><transmittoregistry>False</transmittoregistry></rdprule><rdprule><contacttype>registrant</contacttype><contactfield>Email Address</contactfield><collectiontype>required</collectiontype><transmittoregistry>False</transmittoregistry></rdprule><rdprule><contacttype>registrant</contacttype><contactfield>First Name</contactfield><collectiontype>required</collectiontype><transmittoregistry>False</transmittoregistry></rdprule><rdprule><contacttype>registrant</contacttype><contactfield>Last Name</contactfield><collectiontype>required</collectiontype><transmittoregistry>False</transmittoregistry></rdprule><rdprule><contacttype>registrant</contacttype><contactfield>Organization</contactfield><collectiontype>optional</collectiontype><transmittoregistry>False</transmittoregistry></rdprule><rdprule><contacttype>registrant</contacttype><contactfield>Phone Number</contactfield><collectiontype>required</collectiontype><transmittoregistry>False</transmittoregistry></rdprule><rdprule><contacttype>registrant</contacttype><contactfield>Phone Extension</contactfield><collectiontype>optional</collectiontype><transmittoregistry>False</transmittoregistry></rdprule><rdprule><contacttype>registrant</contacttype><contactfield>Postal Code</contactfield><collectiontype>optional</collectiontype><transmittoregistry>False</transmittoregistry></rdprule><rdprule><contacttype>registrant</contacttype><contactfield>State or Province</contactfield><collectiontype>optional</collectiontype><transmittoregistry>False</transmittoregistry></rdprule></rdprules>
</tld></tlds>
<command>GETTLDDETAILS</command>
<apitype>API.NET</apitype>
<language>eng</language>
<errcount>0</errcount>
<responsecount>0</responsecount>
<minperiod>1</minperiod>
<maxperiod>10</maxperiod>
<server />
<site>eNom</site>
<islockable>True</islockable>
<isrealtimetld>True</isrealtimetld>
<timedifference>+8.00</timedifference>
<exectime>0.172</exectime>
<done>true</done>
<trackingkey>synthetic</trackingkey>
<requestdatetime>10/22/2026 4:36:54 AM</requestdatetime>
<debug />
</interface-response>
XML,
    'Info' => <<<'XML'
<interface-response>
<getdomaininfo><domainname sld="example" tld="com" domainnameid="456">example.com</domainname>
<multy-langsld /><status><expiration>10/22/2027 6:53:00 AM</expiration><deletebydate>10/22/2027 6:53:00 AM</deletebydate><deletetype /><restorable>True</restorable><renewbeforeexpiration /><registrar>eNom, Inc.</registrar><registrationstatus>Registered</registrationstatus><purchase-status>Paid</purchase-status><belongs-to party-id="{00000000-0000-0000-0000-000000000001}">synthetic-uid</belongs-to><escrowhold /><escrowliftdate /><auctionhold>False</auctionhold><auctionliftdate /></status>
<parkingenabled>False</parkingenabled><services><entry name="dnsserver"><enomdns value="YES" isdotname="NO" /><service changable="1">1006</service><configuration changable="0" type="dns"><dns>dns1.name-services.com</dns><dns>dns2.name-services.com</dns><dns>dns3.name-services.com</dns><dns>dns4.name-services.com</dns><dns>dns5.name-services.com</dns></configuration></entry>
<entry name="dnssettings"><service changable="0">1021</service><configuration changable="1" type="host"><host><name>*</name><type>A</type><address>192.0.2.1</address><mxpref>10</mxpref><iseditable>1</iseditable></host></configuration></entry>
<entry name="wsb"><service changable="1">1060</service></entry>
<entry name="emailset"><service changable="1">1048</service></entry>
<entry name="wpps"><service changable="1">1123</service></entry>
<entry name="wbl"><wbl><statusid>0</statusid><statusdescr>Available</statusdescr></wbl></entry>
<entry name="mobilizer"><service changable="0">1117</service><mobilizer /></entry>
<entry name="parking"><service changable="1">1033</service></entry>
<entry name="messaging"><service changable="1">1087</service></entry>
<entry name="map"><service changable="1">1108</service></entry>
<entry name="raasettings" /><entry name="irtpsettings"><irtpsetting><icanncompliant>True</icanncompliant><optout /><transferlock>False</transferlock><transferlockexpdate /></irtpsetting></entry>
<entry name="portalsettings"><service changable="1" /></entry>
<entry name="whoispublicity"><service changable="1" /><whoispublicity><vasitemid /><enabled>False</enabled></whoispublicity>
</entry>
</services>
</getdomaininfo>
<command>GETDOMAININFO</command>
<apitype>API</apitype>
<language>eng</language>
<errcount>0</errcount>
<responsecount>0</responsecount>
<minperiod>1</minperiod>
<maxperiod>10</maxperiod>
<server />
<site>eNom</site>
<islockable>True</islockable>
<isrealtimetld>True</isrealtimetld>
<timedifference>+08.00</timedifference>
<exectime>0.000</exectime>
<done>true</done>
<trackingkey>synthetic</trackingkey>
<requestdatetime>10/22/2026 4:32:49 AM</requestdatetime>
<debug />
</interface-response>
XML,
];
