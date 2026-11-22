import{br as t}from"./main-DPa--7iR.js";const e={async upload(a,o){const r=new FormData;return r.append("file",a,o||a.name||"file"),{url:(await t("/upload",r)).url}}};export{e as s};
