import{cn as n}from"./index-CW5g1_uk.js";const e={async upload(a,r){const o=new FormData;return o.append("file",a,r||a.name||"file"),{url:(await n("/upload",o)).url}}};export{e as s};
