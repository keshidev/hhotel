const labels = {about_image:'About Us photo',about_title:'About Us heading',about_eyebrow:'About Us introductory label',about_description:'About Us description',about_secondary_text:'About Us additional text',start_date:'Start date',end_date:'End date',sort_by:'Sort by',sort_direction:'Order',total_modifications:'Total changes',unique_reservations_modified:'Reservations affected',total_reservations:'Total reservations',status_filter:'Status',check_in:'Check-in date',check_out:'Check-out date'};
const metadata = new Set(['updated_sections','updated_keys','updated_by']);
export const fieldLabel = key => labels[key] || String(key).replace(/[_.]/g,' ').replace(/\b\w/g,c=>c.toUpperCase());
export const reportActivity = log => /report.*(viewed|exported)|(?:viewed|exported).*report/i.test(log.action_activity || '');
export const activityLabel = log => reportActivity(log) ? (/export/i.test(log.action_activity) ? 'Exported report' : 'Viewed report') : /CMS Settings Updated/i.test(log.action_activity || '') ? 'Updated website content' : fieldLabel(log.action_activity || 'Recorded activity');
export const auditArea = log => /about_/.test(JSON.stringify([log.old_values,log.new_values,log.old_value_summary,log.new_value_summary])) ? 'About Us' : (log.module_page || 'General');
export function auditSummary(log) {
 if (reportActivity(log)) return `${activityLabel(log)}: ${log.record_affected || 'Report'}`;
 const keys = log.old_values || log.new_values ? [...new Set([...Object.keys(log.old_values || {}),...Object.keys(log.new_values || {})])] : [...String(log.new_value_summary || log.old_value_summary || '').matchAll(/(?:^|, )([a-z][a-z0-9_]*):/g)].map(m=>m[1]);
 const fields = keys.filter(k=>!metadata.has(k));
 if (/CMS Settings Updated/i.test(log.action_activity || '') && fields.length) return `Updated ${fields.slice(0,3).map(fieldLabel).join(', ')}${fields.length>3 ? ` and ${fields.length-3} more fields` : ''}`;
 return log.record_affected && log.record_affected !== '—' ? `${activityLabel(log)}: ${log.record_affected}` : activityLabel(log);
}
export function readableValue(value, key='') {
 if (value === null || value === undefined || value === '') return 'Not recorded';
 if (typeof value === 'boolean') return value ? 'Yes' : 'No';
 if (typeof value === 'object') return Array.isArray(value) ? value.map(v=>readableValue(v)).join(', ') : Object.entries(value).map(([k,v])=>`${fieldLabel(k)}: ${readableValue(v,k)}`).join('; ');
 const text = String(value);
 if (/^(?:\[|\{)/.test(text)) { try { return readableValue(JSON.parse(text), key); } catch { /* Preserve malformed legacy text. */ } }
 if (/^\d{4}-\d{2}-\d{2}$/.test(text)) {const date=new Date(`${text}T00:00:00`);if(!Number.isNaN(date.getTime()))return date.toLocaleDateString('en-PH',{year:'numeric',month:'long',day:'numeric'});}
 if (key==='sort_direction') return text==='desc'?'Descending':text==='asc'?'Ascending':text;
 if (['sort_by','status','status_filter'].includes(key)) return fieldLabel(text);
 return text;
}
export function detailRows(log) {
 const before=log.old_values || {}, after=log.new_values || {};
 return [...new Set([...Object.keys(before),...Object.keys(after)])].filter(key=>!metadata.has(key)).filter(key=>reportActivity(log)||JSON.stringify(before[key])!==JSON.stringify(after[key])).map(key=>({key,label:fieldLabel(key),before:before[key],after:after[key]}));
}
export function imageUrl(value,key) {
 if (!/(?:image|photo)(?:_url)?$/.test(key) || typeof value!=='string') return null;
 if (value.startsWith('/')&&!value.startsWith('//')) return value;
 try { const url=new URL(value);return url.protocol==='https:' ? url.href : null; } catch {return null;}
}
