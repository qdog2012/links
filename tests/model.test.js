import test from 'node:test';
import assert from 'node:assert/strict';
import { neighborhood, findPath, activeRelation, needsReview, demoData } from '../src/model.js';
const nodes=['a','b','c','d','e'].map(id=>({id}));
const links=[{source:'a',target:'b',status:'active'},{source:'b',target:'c',status:'active'},{source:'c',target:'a',status:'active'},{source:'c',target:'d',status:'ended'}];
test('局部关联按层数遍历，循环不会产生重复对象',()=>{
  assert.deepEqual([...neighborhood('b',nodes,links,1)].sort(),['a','b','c']);
  assert.deepEqual([...neighborhood('b',nodes,links,3)].sort(),['a','b','c']);
  assert.deepEqual([...neighborhood('b',nodes,links,2,true)].sort(),['a','b','c','d']);
});
test('路径查询返回最短路径，并正确处理孤立节点',()=>{
  assert.deepEqual(findPath('b','a',nodes,links),['b','a']);
  assert.equal(findPath('a','d',nodes,links),null);
  assert.deepEqual(findPath('a','d',nodes,links,true),['a','c','d']);
  assert.equal(findPath('a','e',nodes,links),null);
  assert.equal(findPath('missing','a',nodes,links),null);
});
test('尚未生效与已到期的关系不参与有效路径',()=>{
  assert.equal(activeRelation({status:'active',start_date:'2099-01-01'}),false);
  assert.equal(activeRelation({status:'active',end_date:'2000-01-01'}),false);
  assert.equal(activeRelation({status:'ended'}),false);
  assert.equal(activeRelation({status:'active'}),true);
});
test('待核实包含无负责人、超期与孤立对象',()=>{
  const base={id:'a',owner:'维护人',verified_at:new Date().toISOString()};
  assert.equal(needsReview(base,links),false);
  assert.equal(needsReview({...base,owner:''},links),true);
  assert.equal(needsReview({...base,verified_at:'2000-01-01'},links),true);
  assert.equal(needsReview({...base,id:'e'},links),true);
});
test('示例数据的所有关系均指向存在的独立对象',()=>{
  const {entities,relations}=demoData();const ids=new Set(entities.map(n=>n.id));
  assert.equal(ids.size,entities.length);
  assert.ok(relations.every(r=>ids.has(r.source)&&ids.has(r.target)&&r.source!==r.target));
});
