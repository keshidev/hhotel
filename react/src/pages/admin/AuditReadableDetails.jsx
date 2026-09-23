import React, { useState } from 'react';
import { detailRows, reportActivity, readableValue, imageUrl } from '../../utils/auditPresentation';
function Value({value,field}) {
 const [failed,setFailed]=useState(false);
 const url=imageUrl(value,field);
 return url ? <div>{!failed ? <img className="audit-photo-preview" src={url} alt="Recorded photo" referrerPolicy="no-referrer" onError={()=>setFailed(true)} /> : <span>Photo preview unavailable</span>}<a href={url} target="_blank" rel="noopener noreferrer">Open photo</a></div> : <span>{readableValue(value,field)}</span>;
}
export default function AuditReadableDetails({log}) {
 const rows=detailRows(log), report=reportActivity(log);
 return <><section className="audit-modal-section"><h4>{report?'Report details':'Recorded changes'}</h4>{rows.length ? <div className="audit-readable-wrap"><table className="audit-readable-table"><thead><tr><th>{report?'Detail':'Field changed'}</th>{!report&&<th>Before</th>}<th>{report?'Value':'After'}</th></tr></thead><tbody>{rows.map(row=><tr key={row.key}><th scope="row">{row.label}</th>{!report&&<td><Value value={row.before} field={row.key}/></td>}<td><Value value={row.after ?? (report?row.before:undefined)} field={row.key}/></td></tr>)}</tbody></table></div>:<p>No field changes were recorded for this activity.</p>}</section><details className="audit-technical"><summary>Technical details</summary><p>IP address: {log.ip_address || 'Not recorded'}</p><h4>Before — recorded data</h4><pre className="audit-json-block">{JSON.stringify(log.old_values,null,2)}</pre><h4>After — recorded data</h4><pre className="audit-json-block">{JSON.stringify(log.new_values,null,2)}</pre></details></>;
}
