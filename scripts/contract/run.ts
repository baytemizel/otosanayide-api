// Pest'in storage/contract altina yazdigi gercek yanitlari, web ve mobilin kullandigi
// packages/core ayristiricilarindan gecirir. Kaynak repo salt okunur kullanilir.
import {readdirSync,readFileSync} from 'node:fs';
import {join,resolve} from 'node:path';
import {z} from 'zod';

const source=resolve(process.env.OTOSANAYIDE_SOURCE??'../../../..');
const core=(name:string)=>require(join(source,'packages/core',name));
const dir=resolve(process.env.CONTRACT_DIR??'../../storage/contract');

const parsers:Record<string,(raw:unknown,meta:Record<string,string>)=>unknown>={
 search:raw=>core('search-client').decodeSearchResult(raw,'live'),
 account:raw=>core('account-client').parseAccountData(raw),
 'favorites-page':raw=>core('favorites').parseFavoritePage(raw),
 'favorite-status':raw=>core('favorites').favoriteStatus.parse(raw),
 'garage-recycle':raw=>z.object({status:core('garage-recycle').garageRecycleStatus}).strict().parse(raw),
 'garage-backup':raw=>z.object({backup:core('garage-transfer').garageBackupSchema.nullable()}).strict().parse(raw),
 privacy:raw=>core('privacy-requests').privacyOwnerResponse.parse(raw),
 'privacy-queue':raw=>core('privacy-requests').privacyQueueResponse.parse(raw),
 push:raw=>core('push-notifications').pushDevices.parse(raw),
 requests:raw=>core('request-client').parseRequestPage(raw),
 'owner-requests':(raw,meta)=>core('owner-requests-client').parseOwnerRequests(raw,meta.businessId),
 'public-reviews':raw=>core('public-reviews-client').parsePublicReviews(raw),
 'review-cases':raw=>core('review-case-client').casePage.parse(raw),
 'own-review':raw=>z.object({review:core('review-author-client').ownReviewView.nullable()}).strict().parse(raw),
 'own-review-photos':raw=>core('own-review-photo-client').ownPhotoList.parse(raw),
 'business-photos':raw=>core('business-photos').businessPhotoList.parse(raw),
 'public-business-photos':raw=>core('business-photos').publicBusinessPhotos.parse(raw),
 'console-branches':raw=>core('business-console-client').consoleBranches.parse(raw),
 'console-reviews':raw=>core('business-console-client').consoleReviews.parse(raw),
 'managed-profile':raw=>core('business-profile-client').managedProfile.parse(raw),
 activation:raw=>core('activation-client').activationView.parse(raw),
 'business-places':raw=>core('business-places').businessPlaceResult.parse(raw),
};
const files=readdirSync(dir).filter(f=>f.endsWith('.json')).sort();
let failed=0;const seen=new Set<string>();
for(const file of files){
 const {parser,response,meta={}}=JSON.parse(readFileSync(join(dir,file),'utf8'));seen.add(parser);
 const run=parsers[parser];
 if(!run){console.error('NO PARSER',file,parser);failed++;continue;}
 try{run(response,meta);console.log('ok  ',file);}
 catch(e){failed++;console.error('FAIL',file,e instanceof Error?e.message.slice(0,600):e);}
}
const missing=Object.keys(parsers).filter(p=>!seen.has(p));
console.log(`${files.length} yanit, ${failed} hata. Yanit gelmeyen ayristiricilar: ${missing.join(', ')||'yok'}`);
process.exit(failed?1:0);
