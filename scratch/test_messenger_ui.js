// CLI-only browser-controller smoke tests. No network or real message writes.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const base = path.resolve(__dirname, '..');

async function testRole(role) {
  const ready = [], elements = new Map(), queries = new Map();
  const element = id => {
    if (!elements.has(id)) elements.set(id, {id,value:'',files:[],style:{},scrollTop:0,scrollHeight:100,clientHeight:400});
    return elements.get(id);
  };
  class Query {
    constructor(selector) { this.selector = selector; this[0] = element(selector.replace(/^#/,'')); this.length=1; this.attributes={}; this.classes=new Set(); this.markup=''; this.content=''; }
    val(value) { if (value === undefined) return this[0].value; this[0].value=value; return this; }
    text(value) { if (value === undefined) return this.content; this.content=String(value); return this; }
    html(value) { if (value === undefined) return this.markup; this.markup=value; this[0].innerHTML=value; return this; }
    attr(key,value) { if (typeof key === 'object') Object.assign(this.attributes,key); else if (value === undefined) return this.attributes[key] || 'test-csrf'; else this.attributes[key]=value; return this; }
    prop(key,value) { if (value === undefined) return this[0][key]; this[0][key]=value; return this; }
    data(key,value) { return this.attr(key,value); }
    addClass(value) { this.classes.add(value); return this; }
    removeClass(value) { this.classes.delete(value); return this; }
    hasClass(value) { return this.classes.has(value); }
    find(selector) { return this.preview || $(selector); }
    each(callback) { for (const item of this.items || []) callback.call(item); return this; }
    width() { return 1280; }
    css() { return this; }
    on() { return this; } off() { return this; } removeAttr() { return this; }
    prepend() { return this; } append() { return this; } show() { return this; }
    hide() { return this; } toggle() { return this; } toggleClass() { return this; }
    trigger() { return this; } focus() { return this; }
  }
  function $(selector) {
    if (typeof selector === 'function') { ready.push(selector); return; }
    if (selector instanceof Query) return selector;
    const key = typeof selector === 'string' ? selector : 'document';
    if (!queries.has(key)) queries.set(key,new Query(key));
    return queries.get(key);
  }
  let failSend = false, sendCount = 0;
  const counterpart = {id:2,name:'Clinic contact',role:role==='nurse'?'teacher':'nurse',role_label:role==='nurse'?'Teacher':'Clinic Nurse',avatar:'/avatar.svg',presence_status:'online'};
  const conversations = [{id:1,counterpart,last_message:'Hello',unread_count:1}];
  const messages = [{id:10,sender_id:2,message_body:'Hello',created_at:'2026-10-19 09:00:00',avatar:'/avatar.svg'}];
  $.get = (url, args, callback) => {
    if (typeof args === 'function') { callback=args; args={}; }
    const action = args?.action || new URL(url,'http://localhost').searchParams.get('action');
    const response = action==='list_conversations' ? {success:true,conversations} : action==='get_messages' ? {success:true,messages,conversation:{counterpart},interactions_enabled:true} : {success:true,staff:[]};
    const promise = Promise.resolve(response); promise.abort=()=>{}; promise.fail=()=>promise;
    if (callback) promise.then(callback);
    return promise;
  };
  $.post = () => Promise.resolve({success:true});
  $.ajax = async options => { sendCount++; assert.equal(options.data.get('conversation_id'),'1'); if (failSend) throw new Error('Offline'); return {success:true,message_id:11}; };
  const context = vm.createContext({$,console,URL,FormData,Map,Date,Intl,Promise,setInterval:()=>1,clearInterval:()=>{},
    APP_URL:'http://localhost',navigator:{clipboard:{writeText:async()=>{}}},
    BPCModal:{show:()=>{},hide:()=>{}},toastError:()=>{},Swal:{fire:()=>{}},
    window:{MESSENGER_CONFIG:{role,userId:1,avatar:'/avatar.svg',conversationId:0},innerWidth:1280,innerHeight:900,addEventListener:()=>{},confirm:()=>true},
    document:{hidden:false,getElementById:element,querySelector:()=>null}
  });
  for (const file of ['messenger-client.js','messenger.js']) vm.runInContext(fs.readFileSync(path.join(base,'assets/js',file),'utf8'),context,{filename:file});
  ready.forEach(callback=>callback());
  for (let i=0;i<8;i++) await Promise.resolve();
  assert.match(element('messageStream').innerHTML,/Hello/);
  assert.match($('#convList').html(),role==='nurse'?/data-role="teacher"/:/data-role="staff"/);
  assert.equal(vm.runInContext(`escapeHtml('"<script>')`,context),'&quot;&lt;script&gt;');
  const input=element('messageInput');
  input.value='Keep my draft'; failSend=true;
  await vm.runInContext('handleSendMessage({preventDefault(){}})',context);
  assert.equal(input.value,'Keep my draft');
  assert.match($('#messengerNotice').text(),/retry/);
  failSend=false;
  await vm.runInContext('handleSendMessage({preventDefault(){}})',context);
  assert.equal(input.value,'');
  assert.equal(sendCount,2);
  input.value='Only send once';
  await Promise.all([vm.runInContext('handleSendMessage({preventDefault(){}})',context),vm.runInContext('handleSendMessage({preventDefault(){}})',context)]);
  assert.equal(sendCount,3);
  const first = new Query('first'), second = new Query('second');
  first.data('name','alice').data('role',role==='nurse'?'student':'staff').data('unread',1);
  second.data('name','bob').data('role',role==='nurse'?'teacher':'staff').data('unread',0);
  first.preview=new Query('preview1'); second.preview=new Query('preview2');
  $('.messenger-thread-item').items=[first,second];
  vm.runInContext('messageListFilter="unread"; filterConversations()',context);
  assert.equal(first.prop('hidden'),false);assert.equal(second.prop('hidden'),true);
  if(role==='nurse') {
    vm.runInContext('messageListFilter="teacher"; filterConversations()',context);
    assert.equal(first.prop('hidden'),true);assert.equal(second.prop('hidden'),false);
  }
  $('#convSearchInput').val('alice');
  vm.runInContext('messageListFilter="all"; filterConversations()',context);
  assert.equal(first.prop('hidden'),false);assert.equal(second.prop('hidden'),true);
  console.log('PASS '+role+': shared rendering, role/unread/search filters, escaping, failed-send draft, retry and duplicate-send guard.');
}
(async()=>{ for(const role of ['student','teacher','nurse']) await testRole(role); })().catch(error=>{console.error(error);process.exitCode=1;});
