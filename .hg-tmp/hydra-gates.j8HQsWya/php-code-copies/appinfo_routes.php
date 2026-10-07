<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
                                                                                  
                                                                                
                                                                                    
        ['name' => 'settings#update', 'url' => '/api/settings', 'verb' => 'PUT'],
        ['name' => 'settings#reimport', 'url' => '/api/settings/reimport', 'verb' => 'POST'],
                                            
        ['name' => 'setup#status',     'url' => '/api/setup/status',            'verb' => 'GET'],
        ['name' => 'setup#saveConfig', 'url' => '/api/setup/config',            'verb' => 'POST'],
        ['name' => 'setup#runAction',  'url' => '/api/setup/action/{actionId}', 'verb' => 'POST'],

                        
        ['name' => 'settings#getUserSettings', 'url' => '/api/settings/user', 'verb' => 'GET'],
        ['name' => 'settings#updateUserSettings', 'url' => '/api/settings/user', 'verb' => 'PUT'],

                                                                                                 
        ['name' => 'timeEntryWip#retry', 'url' => '/api/time-entries/{uuid}/wip-retry', 'verb' => 'POST'],

                                                                                                                     
        ['name' => 'shillinqAp#retry', 'url' => '/api/expenses/{id}/shillinq-ap/retry', 'verb' => 'POST'],

                                                                                                    
        ['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
        ['name' => 'preferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],

                                                                                
        ['name' => 'leadSource#index', 'url' => '/api/settings/lead-sources', 'verb' => 'GET'],
        ['name' => 'leadSource#create', 'url' => '/api/settings/lead-sources', 'verb' => 'POST'],
        ['name' => 'leadSource#update', 'url' => '/api/settings/lead-sources/{id}', 'verb' => 'PUT'],
        ['name' => 'leadSource#destroy', 'url' => '/api/settings/lead-sources/{id}', 'verb' => 'DELETE'],

                                                                                  
        ['name' => 'contactSync#search', 'url' => '/api/contacts-sync/search', 'verb' => 'GET'],
        ['name' => 'contactSync#import', 'url' => '/api/contacts-sync/import', 'verb' => 'POST'],
        ['name' => 'contactSync#writeBack', 'url' => '/api/contacts-sync/write-back', 'verb' => 'POST'],
                                                                                
                                                                      
        ['name' => 'contactSync#create', 'url' => '/api/contacts-sync/create', 'verb' => 'POST'],

                                                                                                  
                                                                                                  
        ['name' => 'emailSync#getSettings', 'url' => '/api/sync/email/settings', 'verb' => 'GET'],
        ['name' => 'emailSync#saveSettings', 'url' => '/api/sync/email/settings', 'verb' => 'POST'],
        ['name' => 'emailSync#trigger', 'url' => '/api/sync/email/trigger', 'verb' => 'POST'],
        ['name' => 'emailSync#getStatus', 'url' => '/api/sync/email/status', 'verb' => 'GET'],

                       
        ['name' => 'notes#list', 'url' => '/api/notes/{objectType}/{objectId}', 'verb' => 'GET'],
        ['name' => 'notes#create', 'url' => '/api/notes/{objectType}/{objectId}', 'verb' => 'POST'],
        ['name' => 'notes#deleteAll', 'url' => '/api/notes/{objectType}/{objectId}', 'verb' => 'DELETE'],
        ['name' => 'notes#deleteSingle', 'url' => '/api/notes/single/{noteId}', 'verb' => 'DELETE'],

                                                                                  
                                                                                 
                                                                                  
        ['name' => 'entityActivity#index', 'url' => '/api/activity/{entityType}/{entityId}', 'verb' => 'GET'],

                                                                                        
        ['name' => 'requestChannel#index', 'url' => '/api/settings/request-channels', 'verb' => 'GET'],
        ['name' => 'requestChannel#create', 'url' => '/api/settings/request-channels', 'verb' => 'POST'],
        ['name' => 'requestChannel#update', 'url' => '/api/settings/request-channels/{id}', 'verb' => 'PUT'],
        ['name' => 'requestChannel#destroy', 'url' => '/api/settings/request-channels/{id}', 'verb' => 'DELETE'],

                             
        ['name' => 'prospect#index', 'url' => '/api/prospects', 'verb' => 'GET'],

                                                                                                       
        ['name' => 'prospectSettings#index', 'url' => '/api/prospect-settings', 'verb' => 'GET'],
        ['name' => 'prospectSettings#update', 'url' => '/api/prospect-settings', 'verb' => 'PUT'],

                                                                               
                                                                               
                                                                             
                              
                                                                              
                                                                   
        ['name' => 'analytics#overview', 'url' => '/api/analytics/overview', 'verb' => 'GET'],
        ['name' => 'analytics#trends',   'url' => '/api/analytics/trends',   'verb' => 'GET'],
        ['name' => 'analytics#funnels',  'url' => '/api/analytics/funnels',  'verb' => 'GET'],
                                                                                     
        ['name' => 'analytics#commercial', 'url' => '/api/analytics/commercial', 'verb' => 'GET'],
                                                                                            
        ['name' => 'openDeals#index', 'url' => '/api/analytics/open-deals', 'verb' => 'GET'],
                                                                               
                                                                                 
        ['name' => 'worklist#mine', 'url' => '/api/worklist/mine', 'verb' => 'GET'],
        ['name' => 'navi#query',         'url' => '/api/navi/query',         'verb' => 'POST'],
                                                                                            
        ['name' => 'slaAttainment#attainment', 'url' => '/api/sla/attainment', 'verb' => 'GET'],
                                                                                         
        ['name' => 'slaPolicy#create', 'url' => '/api/sla/policies',      'verb' => 'POST'],
        ['name' => 'slaPolicy#update', 'url' => '/api/sla/policies/{id}', 'verb' => 'PUT'],
        ['name' => 'reporting#getKpis',     'url' => '/api/rapportage/kpis',     'verb' => 'GET'],
        ['name' => 'reporting#getChannels', 'url' => '/api/rapportage/channels', 'verb' => 'GET'],
        ['name' => 'reporting#getAgents',   'url' => '/api/rapportage/agents',   'verb' => 'GET'],
        ['name' => 'reporting#getSla',      'url' => '/api/rapportage/sla',      'verb' => 'GET'],
        ['name' => 'reporting#updateSla',   'url' => '/api/rapportage/sla',      'verb' => 'PUT'],
        ['name' => 'reporting#exportCsv',   'url' => '/api/rapportage/export',   'verb' => 'GET'],
                                                                                 
        ['name' => 'rapportage#getPipelineStats', 'url' => '/api/rapportage/pipeline-stats', 'verb' => 'GET'],
                                                                                                  
                                                                                                            
        ['name' => 'customer360#summary', 'url' => '/api/customer-360/summary', 'verb' => 'GET'],
                                                                           
                                                            

                                                      
        ['name' => 'contactmoment#destroy', 'url' => '/api/contactmomenten/{id}', 'verb' => 'DELETE'],

                                                                        
                                                                             
                                                    
        ['name' => 'contactMomentLeaf#index',  'url' => '/api/leaves/contact-moments/{hostId}', 'verb' => 'GET'],
        ['name' => 'contactMomentLeaf#create', 'url' => '/api/leaves/contact-moments/{hostId}', 'verb' => 'POST'],
                                                                               
                                                                             
                                    
        ['name' => 'contactMomentLeaf#fileOnAlsoCase', 'url' => '/api/contact-moments/{momentId}/cases', 'verb' => 'POST'],
        ['name' => 'contactMomentLeaf#unfileFromCase', 'url' => '/api/contact-moments/{momentId}/cases/{caseId}', 'verb' => 'DELETE'],

                                                                             
                                                                         
                                                     
                                                    
        ['name' => 'partyLeaf#panel',       'url' => '/api/leaves/party/{partyId}',                   'verb' => 'GET'],
        ['name' => 'partyLeaf#blocked',     'url' => '/api/parties/{partyId}/blocked/{act}',          'verb' => 'GET'],
        ['name' => 'partyLeaf#acknowledge', 'url' => '/api/party-indicator-values/{valueId}/acknowledge', 'verb' => 'POST'],
        ['name' => 'partyLeaf#setParent',   'url' => '/api/parties/{partyId}/parent',                 'verb' => 'PUT'],

                                                                          
                                                                          
                                                                           
                                                                      
        ['name' => 'partyKind#index',   'url' => '/api/party-kinds',                  'verb' => 'GET'],
        ['name' => 'partyKind#link',    'url' => '/api/party-links',                  'verb' => 'POST'],
        ['name' => 'partyKind#import',  'url' => '/api/party-links/import',           'verb' => 'POST'],
        ['name' => 'partyKind#endLink', 'url' => '/api/party-links/{linkId}',         'verb' => 'DELETE'],

                                                                         
                                                                         
                                               
        ['name' => 'correspondenceLanguage#available',     'url' => '/api/correspondence-languages',                  'verb' => 'GET'],
        ['name' => 'correspondenceLanguage#resolve',       'url' => '/api/parties/{partyId}/correspondence-language', 'verb' => 'GET'],
        ['name' => 'correspondenceLanguage#setPreference', 'url' => '/api/parties/{partyId}/correspondence-language', 'verb' => 'PUT'],

                                                                                  
                                                                         
                                                                        
        ['name' => 'publicSurvey#showInvitation',   'url' => '/survey/i/{token}',            'verb' => 'GET'],
        ['name' => 'publicSurvey#submitInvitation', 'url' => '/survey/i/{token}',            'verb' => 'POST'],
        ['name' => 'satisfaction#clientPanel',      'url' => '/api/satisfaction/client/{clientId}', 'verb' => 'GET'],
        ['name' => 'satisfaction#responseRate',     'url' => '/api/satisfaction/response-rate',     'verb' => 'GET'],

                                                                           
                                                                          
                                                                   
        ['name' => 'programme#workItems',  'url' => '/api/programmes/{programmeId}/work-items', 'verb' => 'GET'],
        ['name' => 'programme#linkWork',   'url' => '/api/programmes/{programmeId}/work-items', 'verb' => 'POST'],
        ['name' => 'programme#progress',   'url' => '/api/programmes/{programmeId}/progress',   'verb' => 'GET'],
        ['name' => 'programme#cycleChart', 'url' => '/api/programme-cycles/{cycleId}/chart',    'verb' => 'GET'],
        ['name' => 'programme#closeCycle', 'url' => '/api/programme-cycles/{cycleId}/close',    'verb' => 'POST'],

                                                                                    
                                                                            
        ['name' => 'cti#webhook',          'url' => '/api/cti/webhook/{platform}',         'verb' => 'POST'],
        ['name' => 'cti#screenPop',        'url' => '/api/cti/screen-pop',                 'verb' => 'POST'],
        ['name' => 'cti#clickToDial',      'url' => '/api/cti/click-to-dial',              'verb' => 'POST'],
        ['name' => 'cti#disposition',      'url' => '/api/cti/contactmoment/{id}/disposition', 'verb' => 'POST'],
        ['name' => 'cti#attachRecording',  'url' => '/api/cti/contactmoment/{id}/recording',   'verb' => 'POST'],
        ['name' => 'cti#getConfig',        'url' => '/api/cti/config',                     'verb' => 'GET'],
        ['name' => 'cti#updateConfig',     'url' => '/api/cti/config',                     'verb' => 'PUT'],
        ['name' => 'cti#testConnection',   'url' => '/api/cti/test-connection',            'verb' => 'GET'],
        ['name' => 'cti#eventLog',         'url' => '/api/cti/event-log',                  'verb' => 'GET'],

                                        
        ['name' => 'callback#attempt', 'url' => '/api/callbacks/{id}/attempts', 'verb' => 'POST'],
        ['name' => 'callback#claim', 'url' => '/api/callbacks/{id}/claim', 'verb' => 'POST'],
        ['name' => 'callback#complete', 'url' => '/api/callbacks/{id}/complete', 'verb' => 'POST'],
        ['name' => 'callback#reassign', 'url' => '/api/callbacks/{id}/reassign', 'verb' => 'POST'],

                                       
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
                                 
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

                                                                                
                                                                                
                                                                         
                                                                             
                                                                             
                                                                  
        ['name' => 'enquiry#submit', 'url' => '/api/enquiry', 'verb' => 'POST'],

                                                                                                
        ['name' => 'schedules#index',   'url' => '/api/schedules',         'verb' => 'GET'],
        ['name' => 'schedules#create',  'url' => '/api/schedules',         'verb' => 'POST'],
        ['name' => 'schedules#pending', 'url' => '/api/schedules/pending', 'verb' => 'GET'],
        ['name' => 'schedules#show',    'url' => '/api/schedules/{id}',    'verb' => 'GET'],
        ['name' => 'schedules#update',  'url' => '/api/schedules/{id}',    'verb' => 'PUT'],
        ['name' => 'schedules#destroy', 'url' => '/api/schedules/{id}',    'verb' => 'DELETE'],

                                                                                                                 
        ['name' => 'activityTimeline#getTimeline',  'url' => '/api/timeline', 'verb' => 'GET'],
        ['name' => 'activityTimeline#getWorklog',   'url' => '/api/worklog',  'verb' => 'GET'],
        ['name' => 'activityTimeline#createWorklog','url' => '/api/worklog',  'verb' => 'POST'],

                                                                        
        ['name' => 'routing#getSuggestions', 'url' => '/api/routing/suggestions', 'verb' => 'GET'],

                                                                                                  
                                                                                                 
                                                                                    
        ['name' => 'posTransaction#taxReport', 'url' => '/api/pos-transactions/tax-report', 'verb' => 'GET'],
        ['name' => 'posTransaction#confirm', 'url' => '/api/pos-transactions/{id}/confirm', 'verb' => 'POST'],
        ['name' => 'posTransaction#settle',  'url' => '/api/pos-transactions/{id}/settle',  'verb' => 'POST'],
        ['name' => 'posTransaction#refund',  'url' => '/api/pos-transactions/{id}/refund',  'verb' => 'POST'],
        ['name' => 'posTransaction#park',    'url' => '/api/pos-transactions/{id}/park',    'verb' => 'POST'],
        ['name' => 'posTransaction#resume',  'url' => '/api/pos-transactions/{id}/resume',  'verb' => 'POST'],

                                                                                           
                                                                                        
        ['name' => 'posCustomer#search',  'url' => '/api/pos-customers/search',          'verb' => 'GET'],
        ['name' => 'posCustomer#history', 'url' => '/api/pos-customers/{id}/history',    'verb' => 'GET'],
        ['name' => 'posCustomer#attach',  'url' => '/api/pos-transactions/{id}/customer', 'verb' => 'POST'],
        ['name' => 'posCustomer#detach',  'url' => '/api/pos-transactions/{id}/customer', 'verb' => 'DELETE'],

                                                                                       
        ['name' => 'posCustomerSettings#index',  'url' => '/api/admin/pos-customer-settings', 'verb' => 'GET'],
        ['name' => 'posCustomerSettings#update', 'url' => '/api/admin/pos-customer-settings', 'verb' => 'POST'],

                                                                                          
        ['name' => 'productCatalog#lookupBarcode', 'url' => '/api/products/barcode-lookup', 'verb' => 'POST'],
        ['name' => 'productCatalog#resolvePrice',  'url' => '/api/products/resolve-price',  'verb' => 'POST'],

                                                                                           
                                                                                      
                                                                                         
        ['name' => 'posReceipt#preview', 'url' => '/api/pos-transactions/{id}/receipt/preview', 'verb' => 'GET'],
        ['name' => 'posReceipt#email',   'url' => '/api/pos-transactions/{id}/receipt/email',   'verb' => 'POST'],
        ['name' => 'posReceipt#print',   'url' => '/api/pos-transactions/{id}/receipt/print',   'verb' => 'POST'],

                                                                                        
                                                                                             
                                                                                    
        ['name' => 'posRefund#confirm', 'url' => '/api/pos-refunds/{id}/confirm', 'verb' => 'POST'],
        ['name' => 'posRefund#reject',  'url' => '/api/pos-refunds/{id}/reject',  'verb' => 'POST'],

                                                                                             
                                                                                               
                                                                                               
                                                                            
        ['name' => 'cashShift#open',    'url' => '/api/pos-shifts',                   'verb' => 'POST'],
        ['name' => 'cashShift#drop',    'url' => '/api/pos-shifts/{id}/drop',         'verb' => 'POST'],
        ['name' => 'cashShift#count',   'url' => '/api/pos-shifts/{id}/count',        'verb' => 'POST'],
        ['name' => 'cashShift#approve', 'url' => '/api/pos-shifts/{id}/diff/approve', 'verb' => 'POST'],
        ['name' => 'cashShift#reject',  'url' => '/api/pos-shifts/{id}/diff/reject',  'verb' => 'POST'],

                                                                                
                                                                         
        ['name' => 'posStaff#authenticate', 'url' => '/api/pos/staff/auth', 'verb' => 'POST'],

        ['name' => 'posRole#index',   'url' => '/api/pos/roles',         'verb' => 'GET'],
        ['name' => 'posRole#create',  'url' => '/api/pos/roles',         'verb' => 'POST'],
        ['name' => 'posRole#show',    'url' => '/api/pos/roles/{id}',    'verb' => 'GET'],
        ['name' => 'posRole#update',  'url' => '/api/pos/roles/{id}',    'verb' => 'PUT'],
        ['name' => 'posRole#destroy', 'url' => '/api/pos/roles/{id}',    'verb' => 'DELETE'],

        ['name' => 'posStaff#index',   'url' => '/api/pos/staff',        'verb' => 'GET'],
        ['name' => 'posStaff#create',  'url' => '/api/pos/staff',        'verb' => 'POST'],
        ['name' => 'posStaff#show',    'url' => '/api/pos/staff/{id}',   'verb' => 'GET'],
        ['name' => 'posStaff#update',  'url' => '/api/pos/staff/{id}',   'verb' => 'PUT'],
        ['name' => 'posStaff#destroy', 'url' => '/api/pos/staff/{id}',   'verb' => 'DELETE'],

                                                                          
        ['name' => 'posStaffReport#staffSales', 'url' => '/api/pos/reports/staff-sales', 'verb' => 'GET'],

                                                                       
                                                                                               
                                                                                                     
                                                                                           
                                                                      
                                                                           
        ['name' => 'posPayment#initiate', 'url' => '/api/pos-payments/{id}/initiate', 'verb' => 'POST'],
        ['name' => 'posPayment#capture',  'url' => '/api/pos-payments/{id}/capture',  'verb' => 'POST'],
        ['name' => 'posPayment#refund',   'url' => '/api/pos-payments/{id}/refund',   'verb' => 'POST'],
        ['name' => 'posPayment#index',    'url' => '/api/payment-providers',          'verb' => 'GET'],
        ['name' => 'posPayment#show',     'url' => '/api/payment-providers/{name}',   'verb' => 'GET'],
        ['name' => 'posPayment#update',   'url' => '/api/payment-providers/{name}',   'verb' => 'PUT'],
        ['name' => 'posPayment#test',     'url' => '/api/payment-providers/{name}/test', 'verb' => 'POST'],
        ['name' => 'posPayment#webhook',  'url' => '/api/pos-payment-webhook/{provider}', 'verb' => 'POST'],

                                                                                          
                                                                                     
                                                                                     
                                                                                    
                                                                                  
        ['name' => 'posBookkeeping#post',         'url' => '/api/pos-bookkeeping/post',         'verb' => 'POST'],

                                                                        
                                                                            
                                                                                
                                                                  
                                                                                       
        ['name' => 'posTender#indexTypes',  'url' => '/api/pos/tender-types',        'verb' => 'GET'],
        ['name' => 'posTender#createType',  'url' => '/api/pos/tender-types',        'verb' => 'POST'],
        ['name' => 'posTender#showType',    'url' => '/api/pos/tender-types/{id}',   'verb' => 'GET'],
        ['name' => 'posTender#updateType',  'url' => '/api/pos/tender-types/{id}',   'verb' => 'PUT'],
        ['name' => 'posTender#destroyType', 'url' => '/api/pos/tender-types/{id}',   'verb' => 'DELETE'],
        ['name' => 'posTender#summary',     'url' => '/api/pos-transactions/{transactionId}/tenders/summary', 'verb' => 'GET'],
        ['name' => 'posTender#indexTenders','url' => '/api/pos-transactions/{transactionId}/tenders',         'verb' => 'GET'],
        ['name' => 'posTender#addTender',   'url' => '/api/pos-transactions/{transactionId}/tenders',         'verb' => 'POST'],
        ['name' => 'posTender#removeTender','url' => '/api/pos-transactions/{transactionId}/tenders/{tenderId}', 'verb' => 'DELETE'],

                                                                             
                                                                                      
                                                                                 
                                                                                      
                                                                           
        ['name' => 'kassakoppelingAudit#index',  'url' => '/api/kassakoppeling/audit',           'verb' => 'GET'],
        ['name' => 'kassakoppelingAudit#create', 'url' => '/api/kassakoppeling/audit',           'verb' => 'POST'],
        ['name' => 'kassakoppelingAudit#export', 'url' => '/api/kassakoppeling/audit/export',    'verb' => 'GET'],
        ['name' => 'kassakoppelingAudit#verify', 'url' => '/api/kassakoppeling/audit/{id}/verify', 'verb' => 'POST'],
        ['name' => 'kassakoppelingAudit#show',   'url' => '/api/kassakoppeling/audit/{id}',      'verb' => 'GET'],

                                                                                
                                                                              
                                                                              
                                                                               
                                                                              
                                                                                

                                              
        ['name' => 'portalTenant#config', 'url' => '/portal/api/tenant-config', 'verb' => 'GET'],

                                                                              
        ['name' => 'portalAuth#login',                'url' => '/portal/api/auth/login',                  'verb' => 'POST'],
        ['name' => 'portalAuth#logout',               'url' => '/portal/api/auth/logout',                 'verb' => 'POST'],
        ['name' => 'portalAuth#extendSession',         'url' => '/portal/api/auth/extend-session',         'verb' => 'POST'],
        ['name' => 'portalAuth#passwordResetRequest',  'url' => '/portal/api/auth/password-reset-request', 'verb' => 'POST'],
        ['name' => 'portalAuth#passwordReset',         'url' => '/portal/api/auth/password-reset',         'verb' => 'POST'],
        ['name' => 'portalAuth#mfaEnroll',             'url' => '/portal/api/auth/mfa-enroll',             'verb' => 'POST'],
        ['name' => 'portalAuth#mfaVerify',             'url' => '/portal/api/auth/mfa-verify',             'verb' => 'POST'],

                                       
        ['name' => 'portalAccount#profile',        'url' => '/portal/api/accounts/profile',       'verb' => 'GET'],
        ['name' => 'portalAccount#updateProfile',  'url' => '/portal/api/accounts/profile',       'verb' => 'PUT'],
        ['name' => 'portalAccount#verifyEmail',    'url' => '/portal/api/accounts/verify-email',  'verb' => 'POST'],
        ['name' => 'portalAccount#requestExport',  'url' => '/portal/api/exports',                'verb' => 'POST'],
        ['name' => 'portalAccount#requestClose',   'url' => '/portal/api/accounts/close',         'verb' => 'POST'],
        ['name' => 'portalAccount#confirmClose',   'url' => '/portal/api/accounts/close/confirm', 'verb' => 'POST'],

                           
        ['name' => 'portalAudit#index', 'url' => '/portal/api/audit-events', 'verb' => 'GET'],

                                                 
        ['name' => 'portalDelegation#index',   'url' => '/portal/api/delegations',      'verb' => 'GET'],
        ['name' => 'portalDelegation#create',  'url' => '/portal/api/delegations',      'verb' => 'POST'],
        ['name' => 'portalDelegation#destroy', 'url' => '/portal/api/delegations/{id}', 'verb' => 'DELETE'],

                                                                                  
        ['name' => 'portalDocument#sign',     'url' => '/portal/api/documents/sign',              'verb' => 'POST'],
        ['name' => 'portalDocument#download', 'url' => '/portal/api/documents/{token}/download',  'verb' => 'GET'],

                                                                 
        ['name' => 'portalRequest#index',  'url' => '/portal/api/requests',           'verb' => 'GET'],
        ['name' => 'portalRequest#create', 'url' => '/portal/api/requests',           'verb' => 'POST'],
        ['name' => 'portalRequest#show',   'url' => '/portal/api/requests/{id}',       'verb' => 'GET'],
        ['name' => 'portalRequest#reply',  'url' => '/portal/api/requests/{id}/reply', 'verb' => 'POST'],

                                                                         
        ['name' => 'portalData#invoices',  'url' => '/portal/api/invoices',       'verb' => 'GET'],
        ['name' => 'portalData#invoice',   'url' => '/portal/api/invoices/{id}',  'verb' => 'GET'],
        ['name' => 'portalData#contracts', 'url' => '/portal/api/contracts',      'verb' => 'GET'],
        ['name' => 'portalData#contract',  'url' => '/portal/api/contracts/{id}', 'verb' => 'GET'],
        ['name' => 'portalData#orders',    'url' => '/portal/api/orders',         'verb' => 'GET'],
        ['name' => 'portalData#order',     'url' => '/portal/api/orders/{id}',    'verb' => 'GET'],

                                                                             
                                                                                
                                                                            
        ['name' => 'forecast#snapshots',      'url' => '/api/forecast/snapshots',     'verb' => 'GET'],
        ['name' => 'forecast#createOverride', 'url' => '/api/forecast/overrides',     'verb' => 'POST'],
        ['name' => 'forecast#deleteOverride', 'url' => '/api/forecast/overrides/{id}', 'verb' => 'DELETE'],

                                                                                          
        ['name' => 'forecastSettings#index',  'url' => '/api/settings/forecast', 'verb' => 'GET'],
        ['name' => 'forecastSettings#update', 'url' => '/api/settings/forecast', 'verb' => 'PUT'],

                                                                                
        ['name' => 'portalAdmin#getConfig',    'url' => '/portal/api/admin/tenant-config', 'verb' => 'GET'],
        ['name' => 'portalAdmin#saveConfig',   'url' => '/portal/api/admin/tenant-config', 'verb' => 'POST'],
        ['name' => 'portalAdmin#accounts',     'url' => '/portal/api/admin/accounts',      'verb' => 'GET'],
        ['name' => 'portalAdmin#auditEvents',  'url' => '/portal/api/admin/audit-events',  'verb' => 'GET'],
        ['name' => 'portalAdmin#getServiceAccount',  'url' => '/portal/api/admin/service-account', 'verb' => 'GET'],
        ['name' => 'portalAdmin#saveServiceAccount', 'url' => '/portal/api/admin/service-account', 'verb' => 'PUT'],

                                                                                 
                                                                            
                                                                                  
                                                                                
                                                                                    
        ['name' => 'portal#services',     'url' => '/portal/api/booking/services',            'verb' => 'GET'],
        ['name' => 'portal#availability', 'url' => '/portal/api/booking/availability',        'verb' => 'GET'],
        ['name' => 'portal#book',         'url' => '/portal/api/booking/book',                'verb' => 'POST'],
        ['name' => 'portal#reschedule',   'url' => '/portal/api/booking/reschedule',          'verb' => 'POST'],
        ['name' => 'portal#cancel',       'url' => '/portal/api/booking/cancel',              'verb' => 'POST'],
        ['name' => 'portal#getBooking',   'url' => '/portal/api/booking/{bookingId}',         'verb' => 'GET'],

                                                                                
                                                                                
                                                                              
        ['name' => 'portalPage#index', 'url' => '/portal', 'verb' => 'GET'],
        ['name' => 'portalPage#subpath', 'url' => '/portal/{path}', 'verb' => 'GET', 'requirements' => ['path' => '^(?!api/).*'], 'defaults' => ['path' => '']],

                                                                                              
                                                                                                  
                                                                                            
                                                                                                     
                                                                                              
                                                                                    
        ['name' => 'exportJob#listDestinations',  'url' => '/api/export/destinations',          'verb' => 'GET'],
        ['name' => 'exportJob#createDestination', 'url' => '/api/export/destinations',          'verb' => 'POST'],
        ['name' => 'exportJob#updateDestination', 'url' => '/api/export/destinations/{id}',      'verb' => 'PUT'],
        ['name' => 'exportJob#deleteDestination', 'url' => '/api/export/destinations/{id}',      'verb' => 'DELETE'],
        ['name' => 'exportJob#testDestination',   'url' => '/api/export/destinations/{id}/test', 'verb' => 'POST'],

        ['name' => 'exportJob#listJobs',   'url' => '/api/export/jobs',              'verb' => 'GET'],
        ['name' => 'exportJob#createJob',  'url' => '/api/export/jobs',              'verb' => 'POST'],
        ['name' => 'exportJob#showJob',    'url' => '/api/export/jobs/{id}',         'verb' => 'GET'],
        ['name' => 'exportJob#updateJob',  'url' => '/api/export/jobs/{id}',         'verb' => 'PUT'],
        ['name' => 'exportJob#deleteJob',  'url' => '/api/export/jobs/{id}',         'verb' => 'DELETE'],
        ['name' => 'exportJob#testRun',    'url' => '/api/export/jobs/{id}/test-run', 'verb' => 'POST'],
        ['name' => 'exportJob#enableJob',  'url' => '/api/export/jobs/{id}/enable',   'verb' => 'POST'],
        ['name' => 'exportJob#disableJob', 'url' => '/api/export/jobs/{id}/disable',  'verb' => 'POST'],

        ['name' => 'exportRun#listRuns', 'url' => '/api/export/runs',           'verb' => 'GET'],
        ['name' => 'exportRun#showRun',  'url' => '/api/export/runs/{id}',       'verb' => 'GET'],
        ['name' => 'exportRun#retryRun', 'url' => '/api/export/runs/{id}/retry', 'verb' => 'POST'],

                                                                           
                                                                            
                                                                       
        ['name' => 'bookingAdmin#reschedule',     'url' => '/api/bookings/{id}/reschedule',     'verb' => 'POST'],
        ['name' => 'bookingAdmin#cancel',         'url' => '/api/bookings/{id}/cancel',         'verb' => 'POST'],
        ['name' => 'bookingAdmin#markCompleted',  'url' => '/api/bookings/{id}/complete',       'verb' => 'POST'],
        ['name' => 'bookingAdmin#markNoShow',     'url' => '/api/bookings/{id}/no-show',        'verb' => 'POST'],
        ['name' => 'bookingAdmin#sendReminder',   'url' => '/api/bookings/{id}/send-reminder',  'verb' => 'POST'],
        ['name' => 'bookingAdmin#confirmDeposit', 'url' => '/api/bookings/{id}/confirm-deposit', 'verb' => 'POST'],

                                                                
        ['name' => 'loyalty#getAccount',         'url' => '/api/loyalty/accounts/{accountId}',          'verb' => 'GET'],
        ['name' => 'loyalty#getAccountHistory',  'url' => '/api/loyalty/accounts/{accountId}/history',  'verb' => 'GET'],
        ['name' => 'loyalty#getRedemptionOptions',    'url' => '/api/loyalty/redemption/options/{programmeId}/{accountId}', 'verb' => 'GET'],
        ['name' => 'loyalty#initiateRedemption',      'url' => '/api/loyalty/redemption/initiate/{accountId}/{optionId}',   'verb' => 'POST'],
        ['name' => 'loyalty#lookupRedemptionCode',    'url' => '/api/loyalty/redemption/{code}/validate',                   'verb' => 'POST'],
        ['name' => 'loyalty#useRedemptionCode',       'url' => '/api/loyalty/redemption/{code}/use',                        'verb' => 'POST'],
        ['name' => 'loyalty#issueGiftCard',     'url' => '/api/loyalty/gift-card/issue',                 'verb' => 'POST'],
        ['name' => 'loyalty#lookupGiftCard',    'url' => '/api/loyalty/gift-card/validate',              'verb' => 'POST'],
        ['name' => 'loyalty#redeemGiftCard',    'url' => '/api/loyalty/gift-card/redeem',                'verb' => 'POST'],
        ['name' => 'loyalty#activateGiftCard',  'url' => '/api/loyalty/gift-card/activate/{giftCardId}', 'verb' => 'POST'],
        ['name' => 'loyalty#activateProgramme', 'url' => '/api/loyalty/programme/{programmeId}/activate', 'verb' => 'POST'],
        ['name' => 'loyaltyReporting#kpis',               'url' => '/api/loyalty/reporting/{programmeId}/kpis',          'verb' => 'GET'],
        ['name' => 'loyaltyReporting#liability',          'url' => '/api/loyalty/reporting/{programmeId}/liability',     'verb' => 'GET'],
        ['name' => 'loyaltyReporting#tierDistribution',   'url' => '/api/loyalty/reporting/{programmeId}/tiers',         'verb' => 'GET'],
        ['name' => 'loyaltyReporting#expiryForecast',     'url' => '/api/loyalty/reporting/{programmeId}/expiry-forecast', 'verb' => 'GET'],
                                                                                    
                                                                                                  

                                                                        
                                                                         
                                                                      

                                                                             
                                                                 
                                                                    
        ['name' => 'blastWebhook#sendgrid', 'url' => '/api/blast-webhooks/sendgrid', 'verb' => 'POST'],
        ['name' => 'blastWebhook#ses',      'url' => '/api/blast-webhooks/ses',      'verb' => 'POST'],
        ['name' => 'blastWebhook#twilio',   'url' => '/api/blast-webhooks/twilio',   'verb' => 'POST'],
                                                                       
                                                                       
        ['name' => 'blastWebhook#brevo',    'url' => '/api/blast-webhooks/brevo',    'verb' => 'POST'],
        ['name' => 'blastWebhook#mailjet',  'url' => '/api/blast-webhooks/mailjet',  'verb' => 'POST'],
        ['name' => 'blastWebhook#mailgun',  'url' => '/api/blast-webhooks/mailgun',  'verb' => 'POST'],
        ['name' => 'blastWebhook#postmark', 'url' => '/api/blast-webhooks/postmark', 'verb' => 'POST'],

                                                                           
                                                                   
                                                                           
        ['name' => 'mailTransport#checkDeliverability', 'url' => '/api/mail-transports/{id}/check-deliverability', 'verb' => 'POST'],

                                                                       
                                                                                  
                                                                     
        ['name' => 'blastTracking#open',  'url' => '/api/blast/track/open/{token}',  'verb' => 'GET'],
        ['name' => 'blastTracking#click', 'url' => '/api/blast/track/click/{token}', 'verb' => 'GET'],

                                                                            
                                                                             
                                                                          
                                                                    
                                                                  
                                                                              
                                                                             
                                                                              
        ['name' => 'listPublic#confirm',          'url' => '/api/lists/confirm/{token}',     'verb' => 'GET'],
        ['name' => 'listPublic#unsubscribePage',  'url' => '/api/lists/unsubscribe/{token}', 'verb' => 'GET'],
        ['name' => 'listPublic#unsubscribe',      'url' => '/api/lists/unsubscribe/{token}', 'verb' => 'POST'],
        ['name' => 'listPublic#preferences',      'url' => '/api/lists/preferences/{token}', 'verb' => 'GET'],
        ['name' => 'listPublic#savePreferences',  'url' => '/api/lists/preferences/{token}', 'verb' => 'POST'],
        ['name' => 'listPublic#subscribe',        'url' => '/api/lists/{id}/subscribe',      'verb' => 'POST'],

                                                                                         
                                                                
                                                                
        ['name' => 'appointmentPaymentWebhook#callback', 'url' => '/api/appointment-payment-webhook', 'verb' => 'POST'],

                                                                                     
                                                                              
                                                                        
        ['name' => 'zgwNotification#inbox', 'url' => '/api/zgw/notificaties/inbox', 'verb' => 'POST'],

                                                                             
                                                                          
                                             
        ['name' => 'messagingWebhook#whatsapp', 'url' => '/api/messaging-webhooks/whatsapp/{providerId}', 'verb' => 'POST'],
        ['name' => 'messagingWebhook#sms',      'url' => '/api/messaging-webhooks/sms/{providerId}',      'verb' => 'POST'],
                                                                         
                                                                      
                                                            
        ['name' => 'messaging#send',         'url' => '/api/messaging/send',                    'verb' => 'POST'],
        ['name' => 'messaging#preflight',    'url' => '/api/messaging/preflight/{contactId}',   'verb' => 'GET'],
        ['name' => 'messaging#consent',      'url' => '/api/messaging/consent',                 'verb' => 'POST'],
        ['name' => 'messaging#testProvider', 'url' => '/api/messaging/providers/{id}/test',     'verb' => 'POST'],
                                                                           
        ['name' => 'messagingAdmin#getServiceAccount',  'url' => '/api/messaging/service-account', 'verb' => 'GET'],
        ['name' => 'messagingAdmin#saveServiceAccount', 'url' => '/api/messaging/service-account', 'verb' => 'PUT'],
                                                                          
                                                                                
                                                                                       
        ['name' => 'semanticHandoff#requestAvailability',    'url' => '/api/handoff/request/{id}/availability',       'verb' => 'GET'],
        ['name' => 'semanticHandoff#convertRequestToCase',   'url' => '/api/handoff/request/{id}/convert-to-case',    'verb' => 'POST'],
        ['name' => 'semanticHandoff#contractAvailability',   'url' => '/api/handoff/contract/{id}/availability',      'verb' => 'GET'],
        ['name' => 'semanticHandoff#sendContractToInvoicing','url' => '/api/handoff/contract/{id}/send-to-invoicing', 'verb' => 'POST'],

                                                                              
                                                                  
                                                                               
                                                              
        ['name' => 'portalQuestion#ask',              'url' => '/api/portal/questions',                     'verb' => 'POST'],
        ['name' => 'portalQuestion#reply',            'url' => '/api/portal/questions/reply',               'verb' => 'POST'],
        ['name' => 'ticketWooRequest#availability',   'url' => '/api/tickets/{id}/woo-request/availability', 'verb' => 'GET'],
        ['name' => 'ticketWooRequest#convert',        'url' => '/api/tickets/{id}/woo-request',              'verb' => 'POST'],
                                                                                        
        ['name' => 'ticketAssign#assignToMe',         'url' => '/api/tickets/{id}/assign-to-me',             'verb' => 'POST'],

                                                                       
                                                                         
                                                                                       
                            
        ['name' => 'billingHandoff#availability', 'url' => '/api/billing/handoff/{clientId}/availability', 'verb' => 'GET'],
        ['name' => 'billingHandoff#trigger',      'url' => '/api/billing/handoff/{clientId}',              'verb' => 'POST'],
                                                                   
                                                                           
                                                                  
        ['name' => 'berichtenboxWebhook#readReceipt',  'url' => '/api/webhook/berichtenbox/read',  'verb' => 'POST'],
        ['name' => 'berichtenboxWebhook#inboundReply', 'url' => '/api/webhook/berichtenbox/reply', 'verb' => 'POST'],
                                                                     
        ['name' => 'berichtenboxAdmin#retry', 'url' => '/api/admin/berichtenbox/message/{id}/retry', 'verb' => 'POST'],
        ['name' => 'berichtenboxAdmin#stats', 'url' => '/api/admin/berichtenbox/stats',              'verb' => 'GET'],
                                                                                   
                                                                        
        ['name' => 'segment#index',         'url' => '/api/segments',                  'verb' => 'GET'],
                                                                       
        ['name' => 'segment#signals',       'url' => '/api/segments/signals',          'verb' => 'GET'],
        ['name' => 'segment#create',        'url' => '/api/segments',                  'verb' => 'POST'],
        ['name' => 'segment#preview',       'url' => '/api/segments/preview',          'verb' => 'POST'],
        ['name' => 'segment#refreshSize',   'url' => '/api/segments/{id}/size',        'verb' => 'POST'],
        ['name' => 'segment#members',       'url' => '/api/segments/{id}/members',     'verb' => 'GET'],
        ['name' => 'segment#show',          'url' => '/api/segments/{id}',             'verb' => 'GET'],
        ['name' => 'segment#update',        'url' => '/api/segments/{id}',             'verb' => 'PATCH'],

                                                                            
                                                                            
                                                                            
                            
        ['name' => 'mailingList#index',         'url' => '/api/mailing-lists',                    'verb' => 'GET'],
        ['name' => 'mailingList#create',        'url' => '/api/mailing-lists',                    'verb' => 'POST'],
        ['name' => 'mailingList#subscriptions', 'url' => '/api/mailing-lists/{id}/subscriptions', 'verb' => 'GET'],
        ['name' => 'mailingList#show',          'url' => '/api/mailing-lists/{id}',               'verb' => 'GET'],
        ['name' => 'mailingList#update',        'url' => '/api/mailing-lists/{id}',               'verb' => 'PATCH'],
        ['name' => 'subscription#softOptIn',    'url' => '/api/subscriptions/soft-opt-in',        'verb' => 'POST'],
        ['name' => 'subscription#unsubscribe',  'url' => '/api/subscriptions/unsubscribe',        'verb' => 'POST'],
        ['name' => 'subscription#forContact',      'url' => '/api/contacts/{contactId}/subscriptions',  'verb' => 'GET'],
        ['name' => 'subscription#preferenceLink',  'url' => '/api/contacts/{contactId}/preference-link', 'verb' => 'GET'],

                                                                                            
                                                                        
        ['name' => 'template#index',   'url' => '/api/templates',          'verb' => 'GET'],
        ['name' => 'template#create',  'url' => '/api/templates',          'verb' => 'POST'],
        ['name' => 'template#preview', 'url' => '/api/templates/{id}/preview', 'verb' => 'GET'],
        ['name' => 'template#validate', 'url' => '/api/templates/{id}/validate', 'verb' => 'POST'],
        ['name' => 'template#show',    'url' => '/api/templates/{id}',     'verb' => 'GET'],
        ['name' => 'template#update',  'url' => '/api/templates/{id}',     'verb' => 'PATCH'],

                                                                         
                                                                           
                                                                       
                                                            
        ['name' => 'article#index',      'url' => '/api/articles',                'verb' => 'GET'],
        ['name' => 'article#create',     'url' => '/api/articles',                'verb' => 'POST'],
        ['name' => 'article#publish',    'url' => '/api/articles/{id}/publish',   'verb' => 'POST'],
        ['name' => 'article#archive',    'url' => '/api/articles/{id}/archive',   'verb' => 'POST'],
        ['name' => 'article#transition', 'url' => '/api/articles/{id}/transition', 'verb' => 'POST'],
        ['name' => 'article#usages',     'url' => '/api/articles/{id}/usages',    'verb' => 'GET'],
        ['name' => 'article#show',       'url' => '/api/articles/{id}',           'verb' => 'GET'],
        ['name' => 'article#update',     'url' => '/api/articles/{id}',           'verb' => 'PATCH'],

                                                                            
                                                                        
                                                                              
                                                                            
                                                                              
                                   
        ['name' => 'socialAccount#index',   'url' => '/api/social-accounts',              'verb' => 'GET'],
        ['name' => 'socialAccount#create',  'url' => '/api/social-accounts',              'verb' => 'POST'],
        ['name' => 'socialAccount#connect', 'url' => '/api/social-accounts/{id}/connect', 'verb' => 'POST'],
        ['name' => 'socialAccount#attach',  'url' => '/api/social-accounts/{id}/attach',  'verb' => 'POST'],
        ['name' => 'socialAccount#revoke',  'url' => '/api/social-accounts/{id}/revoke',  'verb' => 'POST'],
        ['name' => 'socialAccount#sync',    'url' => '/api/social-accounts/{id}/sync',    'verb' => 'POST'],
        ['name' => 'socialAccount#show',    'url' => '/api/social-accounts/{id}',         'verb' => 'GET'],

        ['name' => 'socialPost#performance',  'url' => '/api/social-performance',              'verb' => 'GET'],
        ['name' => 'socialPost#index',        'url' => '/api/social-posts',                    'verb' => 'GET'],
        ['name' => 'socialPost#create',       'url' => '/api/social-posts',                    'verb' => 'POST'],
        ['name' => 'socialPost#submit',       'url' => '/api/social-posts/{id}/submit',        'verb' => 'POST'],
        ['name' => 'socialPost#approve',      'url' => '/api/social-posts/{id}/approve',       'verb' => 'POST'],
        ['name' => 'socialPost#reject',       'url' => '/api/social-posts/{id}/reject',        'verb' => 'POST'],
        ['name' => 'socialPost#publications', 'url' => '/api/social-posts/{id}/publications',  'verb' => 'GET'],
        ['name' => 'socialPost#show',         'url' => '/api/social-posts/{id}',               'verb' => 'GET'],
        ['name' => 'socialPost#update',       'url' => '/api/social-posts/{id}',               'verb' => 'PATCH'],

        ['name' => 'socialPost#retry',              'url' => '/api/social-publications/{id}/retry',         'verb' => 'POST'],
        ['name' => 'socialAdvocacy#share',          'url' => '/api/social-publications/{id}/share',         'verb' => 'GET'],
        ['name' => 'socialAdvocacy#confirmShare',   'url' => '/api/social-publications/{id}/confirm-share', 'verb' => 'POST'],

                                                                                   
                                                                              
        ['name' => 'searchConsole#index', 'url' => '/api/marketing/search-queries', 'verb' => 'GET'],

                                                                                    
                                                                                
                                                                            
                                                                                
        ['name' => 'keywordIntelligence#proposals', 'url' => '/api/marketing/keyword-proposals', 'verb' => 'GET'],
        ['name' => 'keywordIntelligence#targets',   'url' => '/api/marketing/keyword-targets',   'verb' => 'GET'],
        ['name' => 'keywordIntelligence#confirm',   'url' => '/api/marketing/keyword-targets',   'verb' => 'POST'],
        ['name' => 'marketingConnector#matomo',          'url' => '/api/marketing/matomo/report',    'verb' => 'GET'],
        ['name' => 'marketingConnector#connectionAudit', 'url' => '/api/marketing/connection-audit', 'verb' => 'GET'],
        ['name' => 'competitorWatch#index', 'url' => '/api/marketing/watch-events', 'verb' => 'GET'],
        ['name' => 'competitorWatch#run',   'url' => '/api/marketing/competitor-watches/{id}/run', 'verb' => 'POST'],

                                                                                
                                                                          
                                                                          
                                                                          
                                                                           
                                                                      
        ['name' => 'campaign#vocabularies',      'url' => '/api/campaigns/vocabulary',        'verb' => 'GET'],
        ['name' => 'campaign#create',            'url' => '/api/campaigns',                   'verb' => 'POST'],
        ['name' => 'campaign#report',            'url' => '/api/campaigns/{id}/report',       'verb' => 'GET'],
        ['name' => 'campaign#createLandingPage', 'url' => '/api/campaigns/{id}/landing-page', 'verb' => 'POST'],
        ['name' => 'campaign#update',            'url' => '/api/campaigns/{id}',              'verb' => 'PATCH'],

                                                                              
                                                                               
                                                                              
                                                                              
                                     
        ['name' => 'journey#index',   'url' => '/api/journeys',              'verb' => 'GET'],
        ['name' => 'journey#create',  'url' => '/api/journeys',              'verb' => 'POST'],
        ['name' => 'journey#compile', 'url' => '/api/journeys/{id}/compile', 'verb' => 'POST'],
        ['name' => 'journey#runs',    'url' => '/api/journeys/{id}/runs',    'verb' => 'GET'],
        ['name' => 'journey#show',    'url' => '/api/journeys/{id}',         'verb' => 'GET'],
        ['name' => 'journey#update',  'url' => '/api/journeys/{id}',         'verb' => 'PATCH'],

                                                                               
        ['name' => 'weeklyReview#show',     'url' => '/api/weekly-review',          'verb' => 'GET'],
        ['name' => 'weeklyReview#generate', 'url' => '/api/weekly-review/generate', 'verb' => 'POST'],

                                                                                 
                                                                        
        ['name' => 'blast#index',      'url' => '/api/blasts',                     'verb' => 'GET'],
        ['name' => 'blast#create',     'url' => '/api/blasts',                     'verb' => 'POST'],
        ['name' => 'blast#send',       'url' => '/api/blasts/{id}/send',           'verb' => 'POST'],
        ['name' => 'blast#cancel',     'url' => '/api/blasts/{id}/cancel',         'verb' => 'POST'],
        ['name' => 'blast#deliveries', 'url' => '/api/blasts/{id}/deliveries',     'verb' => 'GET'],
        ['name' => 'blast#attribution', 'url' => '/api/blasts/{id}/attribution',   'verb' => 'GET'],
                                                                                   
                                                                                      
        ['name' => 'blast#performance', 'url' => '/api/blasts/{id}/performance',   'verb' => 'GET'],
        ['name' => 'blast#show',       'url' => '/api/blasts/{id}',                'verb' => 'GET'],
        ['name' => 'blast#update',     'url' => '/api/blasts/{id}',                'verb' => 'PATCH'],

                                                                                       
                                                                        
        ['name' => 'brp#lookup',           'url' => '/api/brp/lookup',                    'verb' => 'POST'],
        ['name' => 'brp#revealAddress',    'url' => '/api/brp/contact/{id}/reveal-address', 'verb' => 'POST'],
        ['name' => 'brp#optOutCreate',     'url' => '/api/brp/opt-out',                   'verb' => 'POST'],
        ['name' => 'brp#mutationWebhook',  'url' => '/api/brp/mutations',                 'verb' => 'POST'],
        ['name' => 'brp#monitor',          'url' => '/api/brp/monitor',                   'verb' => 'GET'],
        ['name' => 'brpAdmin#get',                 'url' => '/api/brp/settings',                  'verb' => 'GET'],
        ['name' => 'brpAdmin#save',                'url' => '/api/brp/settings',                  'verb' => 'POST'],
        ['name' => 'brpAdmin#rotateWebhookSecret', 'url' => '/api/brp/settings/webhook-secret',   'verb' => 'POST'],
                                                                 
                                                                           
        ['name' => 'kccWerkplek#stateAction',           'url' => '/api/kcc-werkplek/state',        'verb' => 'GET'],
        ['name' => 'kccWerkplek#setAvailabilityAction', 'url' => '/api/kcc-werkplek/availability', 'verb' => 'PUT'],

                                                                             
                                                                                   
                                                  
        ['name' => 'xWiki#search', 'url' => '/api/xwiki/search',                    'verb' => 'GET'],
        ['name' => 'xWiki#pages',  'url' => '/api/xwiki/pages',                     'verb' => 'GET'],
        ['name' => 'xWiki#page',   'url' => '/api/xwiki/page/{wiki}/{page}',        'verb' => 'GET', 'requirements' => ['page' => '.+']],
        ['name' => 'xWiki#status', 'url' => '/api/xwiki/status',                    'verb' => 'GET'],

                                                                                
                                                                                  
                                                                                 
                                                                                
                                                                                 
                                                                                
                                                                          
                                                                                  

                                                                              
                                                                            
                                                                          
                                                                              
                                                                          
                        

                                                                                   
                                                                                  
                                                                               
        ['name' => 'contract#create',         'url' => '/api/contracts', 'verb' => 'POST'],
        ['name' => 'contract#transition',     'url' => '/api/contracts/{id}/transition', 'verb' => 'POST'],
        ['name' => 'contract#summary',        'url' => '/api/contracts/metrics/summary', 'verb' => 'GET'],
        ['name' => 'contract#renewalMetrics', 'url' => '/api/contracts/metrics/renewal', 'verb' => 'GET'],

                                                                                     
                                                                                 
                                                                              
                                                                    
                                                                                
                                                                                     
        ['name' => 'store#search',       'url' => '/api/store/items',                 'verb' => 'GET'],
        ['name' => 'store#install',      'url' => '/api/store/items/{slug}/install',  'verb' => 'POST', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'store#getSettings',  'url' => '/api/store/settings',              'verb' => 'GET'],
        ['name' => 'store#saveSettings', 'url' => '/api/store/settings',              'verb' => 'PUT'],

                                                                                   
        ['name' => 'dashboard#page', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.*'], 'defaults' => ['path' => '']],
    ],
];
