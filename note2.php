//秘密竞拍（盲拍）合约
//上面的公开拍卖接下来将被扩展为一个秘密竞拍。 
//秘密竞拍的好处是在投标结束前不会有时间压力。 
//在一个透明的计算平台上进行秘密竞拍听起来像是自相矛盾，但密码学可以实现它。
//在投标期间 ，投标人实际上并没有发送她的出价，而只是发送一个哈希版本的出价。 
//由于目前几乎不可能找到两个（足够长的）值，其哈希值是相等的，因此投标人可通过该方式提交报价。 
//在投标结束后，投标人必须公开他们的出价：他们不加密的发送他们的出价，
//合约检查出价的哈希值是否与投标期间提供的相同。

//另一个挑战是如何使拍卖同时做到 绑定和秘密 : 唯一能阻止投标者在她赢得拍卖后不付款的方式是，
//让她将钱连同出价一起发出。 
//但由于资金转移在以太坊中不能被隐藏，因此任何人都可以看到转移的资金。

//下面的合约通过接受任何大于最高出价的值来解决这个问题。 
//当然，因为这只能在披露阶段进行检查，有些出价可能是 无效 的， 
//并且，这是故意的(与高出价一起，它甚至提供了一个明确的标志来标识无效的出价): 
//投标人可以通过设置几个或高或低的无效出价来迷惑竞争对手。


/*

每个竞价者提供一份或者几份大于自己投标价的保证金，然后发送多个竞标价（其中有1个是有效的，其它是无效的）。
投票结束，竞拍主持人公开最终的竞拍最高价得者。
保证金对外是可见的，但是竞标价是加密的，保证金必须大于竞标价。

拍卖流程：开始竞拍、开始揭露竞标价、竞拍结束发起受益人转账

竞拍期间：竞拍者可以发送多笔竞拍到竞拍管理员
揭露竞标价期间：竞拍者通过发送3个数组信息，揭露自己的最终有效的竞拍价格，并且返回多余的资金
竞拍结束：竞拍管理员调用结束函数，并且最终把竞拍款转账给受益人
特点：

不到最后结束，大家都不知道彼此的真实出价。这不是连续公开的竞价。
为了防止 竞价者违约不付款，所以必须付出保证金。但是一旦付出保证金，那么区块的交易里面就可以查询的到交易信息，从而出价就暴露了。
解决方案是提供一个大额的保证金（必须要大于等于自己的竞标价），但这不是自己的最终报价，
通过发送多个竞标价（有效的只有1个），从而实现半秘密的竞标。
缺点分析：
每次发送一次竞拍，无论是真是假，都需要支付大于竞拍价的保证金。所以多次报价占用的保证金特别多。

代码分析：
退款有2种途径

对于那些hash验证失败的报价，或者验证成功但是属性fake的报价，或者不能成为最高价的竞标，
直接通过 refunds里面返回给了用户。是用户调用 reveal函数时的一个伴随的操作
对于那些暂时成为当前报价最高价的 竞标锁定资金，如果被更高的报价竞争掉了，
会返回到pendingReturns里面，需要用户主动调用 withdraw()函数来取回资金。
一个用户其实是可以发起多笔真实的报价，但是只会有最高的一个成功。


*/

// SPDX-License-Identifier: GPL-3.0
pragma solidity >=0.7.0 <0.9.0;

contract BlindAuction {
	
	//一个报价包括：保证金和竞拍hash值，真实的竞拍价在 blindedBid 里面
    //bid.blindedBid = keccak256(value, fake, secret) ，这里面的value才是真实的竞拍价
    struct Bid {
        bytes32 blindedBid;
        uint deposit;
    }

    address payable public beneficiary;
	//竞拍结束时间，用户必须在 竞拍结束之前 发起竞拍
    uint public biddingEnd;
	//揭露竞拍价的结束时间：用户必须在这个时间之前 公开自己的竞拍价
    uint public revealEnd;
    bool public ended;
	
	//一个用户address，可以有多个Bid，但是只有1个是有效的。
    mapping(address => Bid[]) public bids;

    address public highestBidder;
    uint public highestBid;

    // 可以取回的之前的出价
    mapping(address => uint) pendingReturns;

    event AuctionEnded(address winner, uint highestBid);

    /// 使用 modifier 可以更便捷的校验函数的入参。
    /// `onlyBefore` 会被用于后面的 `bid` 函数：
    /// 新的函数体是由 modifier 本身的函数体，并用原函数体替换 `_;` 语句来组成的。
    modifier onlyBefore(uint _time) { require(block.timestamp < _time); _; }
    modifier onlyAfter(uint _time) { require(block.timestamp > _time); _; }

	//参数：竞拍持续时间 、 揭价持续时间、最终受益账户
    constructor(
        uint _biddingTime,
        uint _revealTime,
        address payable _beneficiary
    ) public {
        beneficiary = _beneficiary;
        biddingEnd = block.timestamp + _biddingTime;
        revealEnd = biddingEnd + _revealTime;
    }

    /// 可以通过 `_blindedBid` = keccak256(value, fake, secret)
    /// 设置一个秘密竞拍。
    /// 只有在出价披露阶段被正确披露，已发送的以太币才会被退还。
    /// 如果与出价一起发送的以太币至少为 “value” 且 “fake” 不为真，则出价有效。
    /// 将 “fake” 设置为 true ，然后发送满足订金金额但又不与出价相同的金额是隐藏实际出价的方法。
    /// 同一个地址可以放置多个出价。
    function bid(bytes32 _blindedBid) public payable onlyBefore(biddingEnd)
    {
        bids[msg.sender].push(Bid({
            blindedBid: _blindedBid,
            deposit: msg.value
        }));
    }

    /// 披露你的秘密竞拍出价。
    /// 对于所有正确披露的无效出价以及除最高出价以外的所有出价，你都将获得退款。
    function reveal(uint[] _values,bool[] _fake,bytes32[] _secret) public onlyAfter(biddingEnd) onlyBefore(revealEnd)
    {
        uint length = bids[msg.sender].length;
        require(_values.length == length);
        require(_fake.length == length);
        require(_secret.length == length);

        uint refund;
		//披露价格的时候需要验证
        //1. 身份验证，是根据你的 msg.sender获取 address，这个无法伪造
        //2. 验证blindedBid值是否对的上，出价者必须保证前后blindedBid准确，
		//同时还要保证参数的投标顺序和实际参与竞拍发起的投标顺序一样，否则保证金无法退回
        
		//遍历所有的之前的投标
        for (uint i = 0; i < length; i++) {
			//获取到数据库里面第一个投标
            Bid storage bid = bids[msg.sender][i];
			 //获取到参数里面第一个投标
            (uint value, bool fake, bytes32 secret) = (_values[i], _fake[i], _secret[i]);
			//验证身份和
            if (bid.blindedBid != keccak256(value, fake, secret)) {
                // 出价未能正确披露
                // 不返还订金
                continue;
            }
			//统计总的保证金
            refund += bid.deposit;
			
			//有效的招标： fake为false，且竞拍价 小于保证金
            //通过placeBid函数 进入有效的竞标，如果竞拍暂时成功（高于当前最高出价），
			//同时冻结该笔投标的竞拍价（而不是保证金）
       
            if (!fake && bid.deposit >= value) {
                if (placeBid(msg.sender, value))
                    refund -= value;
            }
            // 使发送者不可能再次认领同一笔订金
            bid.blindedBid = bytes32(0);
        }
		//如果该笔报价 是fake的，或者竞标的价格不满足条件，直接退还对应的保证金
        msg.sender.transfer(refund);
    }

    // 这是一个 "internal" 函数， 意味着它只能在本合约（或继承合约）内被调用
    function placeBid(address bidder, uint value) internal returns (bool success)
    {
        if (value <= highestBid) {
            return false;
        }
        if (highestBidder != address(0)) {
            // 返还之前的最高出价
            pendingReturns[highestBidder] += highestBid;
        }
        highestBid = value;
        highestBidder = bidder;
        return true;
    }

    /// 取回出价（当该出价已被超越）
    function withdraw() public {
        uint amount = pendingReturns[msg.sender];
        if (amount > 0) {
            // 这里很重要，首先要设零值。
            // 因为，作为接收调用的一部分，
            // 接收者可以在 `transfer` 返回之前重新调用该函数。（可查看上面关于‘条件 -> 影响 -> 交互’的标注）
            pendingReturns[msg.sender] = 0;

            msg.sender.transfer(amount);
        }
    }

    /// 结束拍卖，并把最高的出价发送给受益人
    function auctionEnd()
        public
        onlyAfter(revealEnd)
    {
        require(!ended);
        emit AuctionEnded(highestBidder, highestBid);
        ended = true;
        beneficiary.transfer(highestBid);
    }
}