const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const root = require('node:path').join(__dirname, '../../template/uni-app/');
const storage = new Map(), listeners = new Map();
let now = 100000, tick;
const uni = {
 getStorageSync: k => storage.get(k), setStorageSync: (k,v) => storage.set(k,v),
 $emit: (k,v) => listeners.get(k)?.(v), $on: (k,v) => listeners.set(k,v), $off: k => listeners.delete(k)
};
const ctx = vm.createContext({uni, Map, Promise, Number, String, Math, Date: {now: () => now},
 setInterval: f => {tick=f; return 1;}, clearInterval: () => {tick=undefined;}});
vm.runInContext(fs.readFileSync(root+'utils/smsSend.js','utf8').replace(/export /g,''), ctx);
vm.runInContext(fs.readFileSync(root+'mixins/SendVerifyCode.js','utf8').replace(/^import .*\n/, '').replace('export default', 'globalThis.mixin ='),ctx);
async function main() {
 const page = {phone:'13900000000', $t: s=>s};
 Object.assign(page,ctx.mixin.data.call(page));
 for (const [k,v] of Object.entries(ctx.mixin.methods)) page[k]=v.bind(page);
 ctx.mixin.created.call(page);
 let calls=0, finish;
 const post=()=>{calls++; return new Promise(r=>finish=r);};
 const data={phone:page.phone};
 const a=ctx.sendSms(data,post), b=ctx.sendSms(data,post);
 assert.equal(a,b); await Promise.resolve(); assert.equal(calls,1);
 finish({status:200,data:{retry_after:60}}); await a;
 assert.equal(page.disabled,true);
 await assert.rejects(ctx.sendSms(data,post)); assert.equal(calls,1);
 ctx.mixin.onHide.call(page); now+=61000; ctx.mixin.onShow.call(page);
 assert.equal(page.disabled,false,'returning to page expires the countdown');
 await assert.rejects(ctx.sendSms(data,()=>Promise.resolve({status:429,msg:'cooldown',data:{retry_after:17}})));
 assert.equal(page.disabled,true); assert.match(page.text,/17s/);
 now+=17000; tick(); assert.equal(page.disabled,false,'unlocks at zero, not one second later');
 await assert.rejects(ctx.sendSms(data,()=>Promise.reject('network')));
 await ctx.sendSms(data,()=>Promise.resolve({status:200,data:{retry_after:60}}));
 page.phone='13900000001'; ctx.mixin.watch.phone.call(page); assert.equal(page.disabled,false);
 ctx.mixin.beforeDestroy.call(page); assert.equal(listeners.size,0);
 console.log('SMS SEND TEST PASSED: dedup, 429, retry, deadline, hide/show, phone change, cleanup');
}
main().catch(e=>{console.error(e);process.exitCode=1;});
