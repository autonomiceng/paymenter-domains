"""Browser table preferences retain visibility without storing record data."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
from pathlib import Path
import re
import subprocess
import unittest


class Preferences(unittest.TestCase):
    def test_preferences_and_browser_links(self):
        source = (Path(__file__).resolve().parents[1] / "Domains/resources/views/partials/staff-reveal.blade.php").read_text()
        script = re.search(r'<script data-domains-columns[^>]*>(.*?)</script>', source, re.S).group(1)
        probe = r"""
const assert = require('node:assert/strict');
const memory = new Map(); let gesture;
global.localStorage = {getItem:k=>memory.get(k), setItem:(k,v)=>memory.set(k,v)};
global.window = {addEventListener:(name,fn)=>gesture=fn};
const defaults = [{name:'domain',isToggleable:false,isToggled:true},{name:'amount',isToggleable:true,isToggled:true}];
function browser(key) {
  let state=structuredClone(defaults), watch=()=>{};
  const wire = {$get:()=>state,$watch:(name,fn)=>watch=fn,
    applyTableColumnManager:async value=>{state=value;watch(state)}};
  return {open:()=>window.domainsColumns({dataset:{domainsColumnsKey:key}},wire),
    choose:async value=>{state[1].isToggled=value;watch(state)},state:()=>state};
}
(async()=>{
  const first=browser('invoices'); await first.open(); await first.choose(false);
  const reopened=browser('invoices'); await reopened.open(); assert.equal(reopened.state()[1].isToggled,false);
  const separate=browser('domains'); await separate.open(); assert.equal(separate.state()[1].isToggled,true);
  await reopened.choose(true); const reset=browser('invoices'); await reset.open(); assert.equal(reset.state()[1].isToggled,true);
  memory.set('domains.columns.invoices',JSON.stringify({domain:false,amount:false,secret:'unused'}));
  const forged=browser('invoices'); await forged.open(); assert.equal(forged.state()[0].isToggled,true);
  assert.equal(forged.state().length,2);
  memory.set('domains.columns.invoices','broken json'); await browser('invoices').open();
  global.localStorage.getItem=()=>{throw Error('storage blocked')}; await browser('invoices').open();
  for(const modifier of ['ctrlKey','metaKey','shiftKey']) {
    let stopped=false, prevented=false;
    gesture({[modifier]:true,target:{closest:()=>true},stopPropagation:()=>stopped=true,preventDefault:()=>prevented=true});
    assert.equal(stopped,true); assert.equal(prevented,false);
  }
})().catch(error=>{console.error(error);process.exitCode=1});
"""
        setup, run = probe.split("(async()=>{", 1)
        result = subprocess.run(["node", "-e", setup + script + "(async()=>{" + run], text=True, capture_output=True, timeout=15)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
